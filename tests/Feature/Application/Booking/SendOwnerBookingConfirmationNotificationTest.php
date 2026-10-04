<?php

use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Booking\Listeners\SendOwnerBookingConfirmationNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Channels\CentralChannelProvider;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\OwnerNotifier;
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
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Registra cada sendTemplateMessage() con el Channel por el que salió —
 * así se prueba que el aviso al owner sale por el CENTRAL, no solo que se
 * llamó a "algo". Con $throwOnNextCall falla una sola vez (simula Meta
 * caído en el primer intento).
 */
function ownerConfirmationFakeClient(array &$sent, ?Throwable $throwOnNextCall = null): ChannelClientInterface
{
    return new class($sent, $throwOnNextCall) implements ChannelClientInterface
    {
        public function __construct(private array &$sent, private ?Throwable $throwOnNextCall) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void {}

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void
        {
            if ($this->throwOnNextCall !== null) {
                $toThrow = $this->throwOnNextCall;
                $this->throwOnNextCall = null;

                throw $toThrow;
            }

            $this->sent[] = compact('channel', 'to', 'templateName', 'language', 'bodyParameters');
        }

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
    };
}

function ownerNotificationFixtureChannel(string $phoneNumberId, ChannelRole $role): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

/**
 * Organization con owner, con su BUSINESS vinculado (para probar que el
 * aviso al owner NO sale por ahí) y un Resource asignado (para probar que
 * el destinatario nunca sale del recurso).
 */
function ownerConfirmationFixtureBooking(?string $ownerPhone = '+573001234567', bool $resourceActive = true, bool $withResource = true): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => $ownerPhone, 'timezone' => 'America/Bogota']);
    ownerNotificationFixtureChannel('wamid-owner-confirmation-business', ChannelRole::BUSINESS)
        ->organizations()->attach($org->id, ['is_primary' => true]);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);

    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 20:00', 'ends_at' => '2026-10-05 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    if ($withResource) {
        $resource = Resource::create([
            'organization_id' => $org->id, 'location_id' => $location->id,
            'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos',
            'is_active' => $resourceActive,
        ]);
        BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);
    }

    return $booking->fresh();
}

function buildOwnerConfirmationListener(ChannelClientInterface $client, ?ProfessionalNotificationIdempotency $idempotency = null): SendOwnerBookingConfirmationNotification
{
    return new SendOwnerBookingConfirmationNotification(
        new OwnerNotifier(new CentralChannelProvider, $client),
        $idempotency ?? new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent)))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('nueva reserva: avisa al owner_phone por el CENTRAL, con el template y los parámetros correctos', function () {
    $central = ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking();
    $sent = [];

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['channel']->isBusiness())->toBeFalse();
    expect($sent[0]['to'])->toBe('+573001234567');
    expect($sent[0]['templateName'])->toBe('reserva_nueva_profesional');
    expect($sent[0]['language'])->toBe('es');
    expect($sent[0]['bodyParameters'])->toBe(['Corte', 'Ana', '05/10/2026', '20:00']);
});

test('el destinatario es siempre el owner — el Resource asignado no lo altera', function () {
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking();
    $sent = [];

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($booking));

    expect($booking->bookingResources)->toHaveCount(1);
    expect($sent[0]['to'])->toBe('+573001234567');
});

test('Resource inactivo o sin BookingResource: el owner igual recibe el aviso — el destinatario no depende del recurso', function () {
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $inactive = ownerConfirmationFixtureBooking(resourceActive: false);
    $sent = [];

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($inactive));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['to'])->toBe('+573001234567');

    // Antes (Fase 2B) una reserva sin BookingResource no avisaba a nadie,
    // porque no había profesional; el owner existe siempre.
    $inactive->organization->channels()->detach();
    $inactive->organization->update(['owner_phone' => '+573001111111']);
    $withoutResource = Booking::create([
        'organization_id' => $inactive->organization_id, 'location_id' => $inactive->location_id,
        'service_id' => $inactive->service_id, 'customer_id' => $inactive->customer_id,
        'starts_at' => '2026-10-06 20:00', 'ends_at' => '2026-10-06 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ])->fresh();

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($withoutResource));

    expect($sent)->toHaveCount(2);
    expect($sent[1]['to'])->toBe('+573001111111');
});

test('formatea la hora en la timezone de la Organization, no en la de la app', function () {
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking();
    $booking->organization->update(['timezone' => 'UTC']);
    $sent = [];

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($booking->fresh()));

    expect($sent[0]['bodyParameters'][2])->toBe('06/10/2026');
    expect($sent[0]['bodyParameters'][3])->toBe('01:00');
});

test('Organization sin owner_phone: no envía, loguea el motivo y no lanza (no hay a quién reintentar)', function () {
    Log::spy();
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking(ownerPhone: null);
    $sent = [];

    buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($booking));

    expect($sent)->toBeEmpty();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'owner_phone'));
});

test('sin CENTRAL activo: NotificationDeliveryException (la cola reintenta) y nunca cae al BUSINESS de la Organization', function () {
    $booking = ownerConfirmationFixtureBooking();
    $sent = [];

    expect(fn () => buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent))->handle(new BookingConfirmed($booking)))
        ->toThrow(NotificationDeliveryException::class);
    expect($sent)->toBeEmpty();
});

test('idempotencia: 2 llamadas a handle() para la misma reserva mandan un solo aviso', function () {
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking();
    $sent = [];
    $listener = buildOwnerConfirmationListener(ownerConfirmationFakeClient($sent));

    $listener->handle(new BookingConfirmed($booking));
    $listener->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
});

test('si el envío falla, no se marca como enviado — el reintento manda exactamente un aviso', function () {
    ownerNotificationFixtureChannel('wamid-owner-confirmation-central', ChannelRole::CENTRAL);
    $booking = ownerConfirmationFixtureBooking();
    $idempotency = new ProfessionalNotificationIdempotency;
    $sent = [];
    $client = ownerConfirmationFakeClient($sent, new NotificationDeliveryException('Meta caído'));

    expect(fn () => buildOwnerConfirmationListener($client, $idempotency)->handle(new BookingConfirmed($booking)))
        ->toThrow(NotificationDeliveryException::class);
    expect($sent)->toBeEmpty();

    buildOwnerConfirmationListener($client, $idempotency)->handle(new BookingConfirmed($booking));
    buildOwnerConfirmationListener($client, $idempotency)->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
});

test('BookingScheduler dispara BookingConfirmed y encola AMBOS listeners (cliente y owner) — el del cliente sigue intacto', function () {
    Queue::fake();

    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    ServiceResourceRequirement::create(['service_id' => $service->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);
    $resource = Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos',
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
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendOwnerBookingConfirmationNotification::class);
});
