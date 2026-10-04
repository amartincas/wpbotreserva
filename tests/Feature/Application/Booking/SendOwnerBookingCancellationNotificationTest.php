<?php

use App\Application\Booking\Listeners\SendBookingCancellationNotification;
use App\Application\Booking\Listeners\SendOwnerBookingCancellationNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Channels\CentralChannelProvider;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\OwnerNotifier;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

function ownerCancellationFakeClient(array &$sent, ?Throwable $throwOnNextCall = null): ChannelClientInterface
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

function ownerCancellationFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-owner-cancellation-central',
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function ownerCancellationFixtureBooking(?string $cancellationReason = null): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001234567', 'timezone' => 'America/Bogota']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);

    return Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 20:00', 'ends_at' => '2026-10-05 20:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CANCELLED,
        'cancelled_at' => now(), 'cancellation_reason' => $cancellationReason,
    ])->fresh();
}

function buildOwnerCancellationListener(ChannelClientInterface $client, ?ProfessionalNotificationIdempotency $idempotency = null): SendOwnerBookingCancellationNotification
{
    return new SendOwnerBookingCancellationNotification(
        new OwnerNotifier(new CentralChannelProvider, $client),
        $idempotency ?? new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(buildOwnerCancellationListener(ownerCancellationFakeClient($sent)))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('cancelación: avisa al owner_phone por el CENTRAL, sin motivo', function () {
    $central = ownerCancellationFixtureCentral();
    $booking = ownerCancellationFixtureBooking();
    $sent = [];

    buildOwnerCancellationListener(ownerCancellationFakeClient($sent))->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['to'])->toBe('+573001234567');
    expect($sent[0]['templateName'])->toBe('reserva_cancelada_profesional');
    expect($sent[0]['bodyParameters'])->toBe(['Corte', 'Ana', '05/10/2026', '20:00', '']);
});

test('con motivo de cancelación, se incluye tal cual en el último parámetro — nunca inventado', function () {
    ownerCancellationFixtureCentral();
    $booking = ownerCancellationFixtureBooking('El cliente tuvo una emergencia');
    $sent = [];

    buildOwnerCancellationListener(ownerCancellationFakeClient($sent))->handle(new BookingCancelled($booking));

    expect($sent[0]['bodyParameters'][4])->toBe(' Motivo: El cliente tuvo una emergencia.');
});

test('idempotencia: 2 llamadas para la misma cancelación mandan un solo aviso', function () {
    ownerCancellationFixtureCentral();
    $booking = ownerCancellationFixtureBooking();
    $sent = [];
    $listener = buildOwnerCancellationListener(ownerCancellationFakeClient($sent));

    $listener->handle(new BookingCancelled($booking));
    $listener->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
});

test('si el envío falla, no se marca como enviado — el reintento manda exactamente un aviso', function () {
    ownerCancellationFixtureCentral();
    $booking = ownerCancellationFixtureBooking();
    $idempotency = new ProfessionalNotificationIdempotency;
    $sent = [];
    $client = ownerCancellationFakeClient($sent, new NotificationDeliveryException('Meta caído'));

    expect(fn () => buildOwnerCancellationListener($client, $idempotency)->handle(new BookingCancelled($booking)))
        ->toThrow(NotificationDeliveryException::class);

    buildOwnerCancellationListener($client, $idempotency)->handle(new BookingCancelled($booking));
    buildOwnerCancellationListener($client, $idempotency)->handle(new BookingCancelled($booking));

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
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendOwnerBookingCancellationNotification::class);
});
