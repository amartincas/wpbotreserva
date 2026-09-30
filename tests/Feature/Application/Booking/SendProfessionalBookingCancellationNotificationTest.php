<?php

use App\Application\Booking\Listeners\SendBookingCancellationNotification;
use App\Application\Booking\Listeners\SendProfessionalBookingCancellationNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

function professionalCancellationFakeSender(array &$sent, ?Throwable $throwOnNextCall = null): NotificationSenderInterface
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

function professionalCancellationFixtureBooking(?string $cancellationReason = null): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001234567']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);
    $resource = Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => '+573005556677',
    ]);

    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 20:00', 'ends_at' => '2026-10-05 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CANCELLED,
        'cancelled_at' => now(), 'cancellation_reason' => $cancellationReason,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    return $booking->fresh(['bookingResources']);
}

function buildProfessionalCancellationListener(NotificationSenderInterface $sender): SendProfessionalBookingCancellationNotification
{
    return new SendProfessionalBookingCancellationNotification(
        new ProfessionalRecipientResolver,
        $sender,
        new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(new SendProfessionalBookingCancellationNotification(
        new ProfessionalRecipientResolver, professionalCancellationFakeSender($sent), new ProfessionalNotificationIdempotency,
    ))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('handle() manda sendTemplate() al profesional con template/parámetros correctos, sin motivo', function () {
    $booking = professionalCancellationFixtureBooking(cancellationReason: null);
    $sent = [];

    buildProfessionalCancellationListener(professionalCancellationFakeSender($sent))->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573005556677');
    expect($sent[0]['toPhoneE164'])->not->toBe($booking->organization->owner_phone);
    expect($sent[0]['templateName'])->toBe('reserva_cancelada_profesional');
    expect($sent[0]['language'])->toBe('es');
    expect($sent[0]['bodyParameters'])->toBe(['Corte', 'Ana', '05/10/2026', '20:00', '']);
});

test('con motivo de cancelación, se incluye tal cual en el último parámetro — nunca inventado', function () {
    $booking = professionalCancellationFixtureBooking(cancellationReason: 'El cliente tuvo una emergencia');
    $sent = [];

    buildProfessionalCancellationListener(professionalCancellationFakeSender($sent))->handle(new BookingCancelled($booking));

    expect($sent[0]['bodyParameters'][4])->toBe(' Motivo: El cliente tuvo una emergencia.');
});

test('idempotencia: 2 llamadas a handle() para la misma cancelación mandan un solo sendTemplate()', function () {
    $booking = professionalCancellationFixtureBooking();
    $sent = [];
    $idempotency = new ProfessionalNotificationIdempotency;
    $listener = new SendProfessionalBookingCancellationNotification(new ProfessionalRecipientResolver, professionalCancellationFakeSender($sent), $idempotency);

    $listener->handle(new BookingCancelled($booking));
    $listener->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
});

test('si el sender falla, no se marca como enviado', function () {
    $booking = professionalCancellationFixtureBooking();
    $idempotency = new ProfessionalNotificationIdempotency;

    $sent = [];
    $failingListener = new SendProfessionalBookingCancellationNotification(
        new ProfessionalRecipientResolver,
        professionalCancellationFakeSender($sent, new NotificationDeliveryException('Meta caído')),
        $idempotency,
    );

    expect(fn () => $failingListener->handle(new BookingCancelled($booking)))->toThrow(NotificationDeliveryException::class);
    expect($sent)->toBeEmpty();

    $retryListener = new SendProfessionalBookingCancellationNotification(new ProfessionalRecipientResolver, professionalCancellationFakeSender($sent), $idempotency);
    $retryListener->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
});

test('BookingScheduler::cancel() dispara BookingCancelled y encola AMBOS listeners — el del cliente sigue intacto', function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    app(BookingSchedulerInterface::class)->cancel($booking);

    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendBookingCancellationNotification::class);
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendProfessionalBookingCancellationNotification::class);
});
