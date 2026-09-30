<?php

use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Booking\Listeners\SendProfessionalBookingConfirmationNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<int, array{organization: Organization, toPhoneE164: string, templateName: string, language: string, bodyParameters: array}>  $sent
 */
function professionalConfirmationFakeSender(array &$sent, ?Throwable $throwOnNextCall = null): NotificationSenderInterface
{
    return new class($sent, $throwOnNextCall) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent, private ?Throwable $throwOnNextCall) {}

        public function send($organization, string $toPhoneE164, string $message): void {}

        public function sendTemplate($organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void
        {
            if ($this->throwOnNextCall !== null) {
                $toThrow = $this->throwOnNextCall;
                $this->throwOnNextCall = null;

                throw $toThrow;
            }

            $this->sent[] = compact('organization', 'toPhoneE164', 'templateName', 'language', 'bodyParameters');
        }

        public function sendButtons($organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
}

function professionalConfirmationFixtureBooking(?string $contactPhone = '+573005556677'): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001234567', 'timezone' => 'America/Bogota']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);
    $resource = Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => $contactPhone,
    ]);

    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 20:00', 'ends_at' => '2026-10-05 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    return $booking->fresh(['bookingResources']);
}

function buildProfessionalConfirmationListener(NotificationSenderInterface $sender): SendProfessionalBookingConfirmationNotification
{
    return new SendProfessionalBookingConfirmationNotification(
        new ProfessionalRecipientResolver,
        $sender,
        new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(new SendProfessionalBookingConfirmationNotification(
        new ProfessionalRecipientResolver, professionalConfirmationFakeSender($sent), new ProfessionalNotificationIdempotency,
    ))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('handle() manda sendTemplate() al contact_phone del profesional, nunca a owner_phone, con el template y parámetros correctos', function () {
    $booking = professionalConfirmationFixtureBooking();
    $sent = [];
    $listener = buildProfessionalConfirmationListener(professionalConfirmationFakeSender($sent));

    $listener->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573005556677');
    expect($sent[0]['toPhoneE164'])->not->toBe($booking->organization->owner_phone);
    expect($sent[0]['templateName'])->toBe('reserva_nueva_profesional');
    expect($sent[0]['language'])->toBe('es');
    // APP_TIMEZONE del proyecto ya es America/Bogota (verificado: config('app.timezone')),
    // igual que el timezone por default de esta Organization — la conversión
    // es un no-op en este caso, la hora se muestra tal cual se guardó.
    expect($sent[0]['bodyParameters'])->toBe(['Corte', 'Ana', '05/10/2026', '20:00']);
});

test('formatea la hora en la timezone de la Organization, no en la timezone de la app — prueba real de conversión', function () {
    // Timezone deliberadamente distinta de APP_TIMEZONE (America/Bogota) y
    // del default de Organization, para probar que el código realmente
    // convierte a $organization->timezone y no solo coincide por default.
    // Verificado con Carbon real: 2026-10-05 20:00 (interpretado en
    // America/Bogota, el timezone de la app) equivale a 2026-10-06 01:00 UTC.
    $booking = professionalConfirmationFixtureBooking();
    $booking->organization->update(['timezone' => 'UTC']);
    $booking = $booking->fresh(['bookingResources']);

    $sent = [];
    buildProfessionalConfirmationListener(professionalConfirmationFakeSender($sent))->handle(new BookingConfirmed($booking));

    expect($sent[0]['bodyParameters'][2])->toBe('06/10/2026');
    expect($sent[0]['bodyParameters'][3])->toBe('01:00');
});

test('sin BookingResource: no llama al sender, no lanza', function () {
    $org = Organization::create(['name' => 'Sin recurso', 'owner_phone' => '+573001234567']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 20:00', 'ends_at' => '2026-10-05 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ])->fresh(['bookingResources']);

    $sent = [];
    buildProfessionalConfirmationListener(professionalConfirmationFakeSender($sent))->handle(new BookingConfirmed($booking));

    expect($sent)->toBeEmpty();
});

test('idempotencia: 2 llamadas a handle() para el mismo booking mandan un solo sendTemplate()', function () {
    $booking = professionalConfirmationFixtureBooking();
    $sent = [];
    $idempotency = new ProfessionalNotificationIdempotency;
    $listener = new SendProfessionalBookingConfirmationNotification(new ProfessionalRecipientResolver, professionalConfirmationFakeSender($sent), $idempotency);

    $listener->handle(new BookingConfirmed($booking));
    $listener->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
});

test('si el sender falla, no se marca como enviado — un reintento posterior sí reintenta el envío real', function () {
    $booking = professionalConfirmationFixtureBooking();
    $idempotency = new ProfessionalNotificationIdempotency;

    $sent = [];
    $failingSender = professionalConfirmationFakeSender($sent, new NotificationDeliveryException('Meta caído'));
    $failingListener = new SendProfessionalBookingConfirmationNotification(new ProfessionalRecipientResolver, $failingSender, $idempotency);

    expect(fn () => $failingListener->handle(new BookingConfirmed($booking)))
        ->toThrow(NotificationDeliveryException::class);
    expect($sent)->toBeEmpty();

    // Reintento con un sender que sí funciona — debe intentar el envío real,
    // no saltarlo como si ya se hubiera marcado.
    $workingSender = professionalConfirmationFakeSender($sent);
    $retryListener = new SendProfessionalBookingConfirmationNotification(new ProfessionalRecipientResolver, $workingSender, $idempotency);
    $retryListener->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
});

test('BookingScheduler dispara BookingConfirmed y encola AMBOS listeners (cliente y profesional) — el del cliente sigue intacto', function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    ServiceResourceRequirement::create(['service_id' => $service->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);
    $resource = Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => '+573005556677',
    ]);
    $service->resources()->attach($resource->id);
    ResourceSchedule::create([
        'resource_id' => $resource->id, 'weekday' => CarbonImmutable::parse('2026-09-07')->dayOfWeek,
        'start_time' => '09:00', 'end_time' => '12:00',
    ]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877']);

    (new BookingScheduler(new AvailabilityCalculator))
        ->schedule($service, $location, $customer, CarbonImmutable::parse('2026-09-07 10:00'), $resource);

    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendBookingConfirmationNotification::class);
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendProfessionalBookingConfirmationNotification::class);
});
