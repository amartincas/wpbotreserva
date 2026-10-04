<?php

use App\Application\Booking\Listeners\SendBookingRescheduleNotification;
use App\Application\Booking\Listeners\SendOwnerBookingRescheduleNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Channels\CentralChannelProvider;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\OwnerNotifier;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Events\BookingRescheduled;
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
use Illuminate\Support\Facades\Queue;

function ownerRescheduleFakeClient(array &$sent, ?Throwable $throwOnNextCall = null): ChannelClientInterface
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

function ownerRescheduleFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-owner-reschedule-central',
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function ownerRescheduleFixtureBooking(): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001234567', 'timezone' => 'America/Bogota']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);

    return Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 21:00', 'ends_at' => '2026-10-05 21:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ])->fresh();
}

function buildOwnerRescheduleListener(ChannelClientInterface $client, ?ProfessionalNotificationIdempotency $idempotency = null): SendOwnerBookingRescheduleNotification
{
    return new SendOwnerBookingRescheduleNotification(
        new OwnerNotifier(new CentralChannelProvider, $client),
        $idempotency ?? new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(buildOwnerRescheduleListener(ownerRescheduleFakeClient($sent)))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('modificación: avisa al owner_phone por el CENTRAL con horario anterior y nuevo', function () {
    $central = ownerRescheduleFixtureCentral();
    $booking = ownerRescheduleFixtureBooking();
    $sent = [];

    buildOwnerRescheduleListener(ownerRescheduleFakeClient($sent))
        ->handle(new BookingRescheduled($booking, CarbonImmutable::parse('2026-10-05 15:00')));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['to'])->toBe('+573001234567');
    expect($sent[0]['templateName'])->toBe('reserva_modificada_profesional');
    expect($sent[0]['bodyParameters'][0])->toBe('Corte');
    expect($sent[0]['bodyParameters'][1])->toBe('Ana');
    expect($sent[0]['bodyParameters'][2])->toContain('15:00'); // anterior
    expect($sent[0]['bodyParameters'][3])->toContain('21:00'); // nuevo
});

test('repetir exactamente el mismo evento de reprogramación no manda un segundo aviso', function () {
    ownerRescheduleFixtureCentral();
    $booking = ownerRescheduleFixtureBooking();
    $previousStartsAt = CarbonImmutable::parse('2026-10-05 15:00');
    $sent = [];
    $listener = buildOwnerRescheduleListener(ownerRescheduleFakeClient($sent));

    $listener->handle(new BookingRescheduled($booking, $previousStartsAt));
    $listener->handle(new BookingRescheduled($booking, $previousStartsAt));

    expect($sent)->toHaveCount(1);
});

test('dos reprogramaciones legítimas y distintas de la MISMA reserva generan dos avisos', function () {
    ownerRescheduleFixtureCentral();
    $booking = ownerRescheduleFixtureBooking();
    $sent = [];
    $listener = buildOwnerRescheduleListener(ownerRescheduleFakeClient($sent));

    $listener->handle(new BookingRescheduled($booking, CarbonImmutable::parse('2026-10-05 15:00')));

    $booking->update(['starts_at' => '2026-10-06 18:00', 'ends_at' => '2026-10-06 18:30']);
    $listener->handle(new BookingRescheduled($booking->fresh(), CarbonImmutable::parse('2026-10-05 21:00')));

    expect($sent)->toHaveCount(2);
    expect($sent[1]['bodyParameters'][3])->toContain('18:00');
});

test('si el envío falla, no se marca como enviado — el reintento manda exactamente un aviso', function () {
    ownerRescheduleFixtureCentral();
    $booking = ownerRescheduleFixtureBooking();
    $previousStartsAt = CarbonImmutable::parse('2026-10-05 15:00');
    $idempotency = new ProfessionalNotificationIdempotency;
    $sent = [];
    $client = ownerRescheduleFakeClient($sent, new NotificationDeliveryException('Meta caído'));

    expect(fn () => buildOwnerRescheduleListener($client, $idempotency)->handle(new BookingRescheduled($booking, $previousStartsAt)))
        ->toThrow(NotificationDeliveryException::class);

    buildOwnerRescheduleListener($client, $idempotency)->handle(new BookingRescheduled($booking, $previousStartsAt));
    buildOwnerRescheduleListener($client, $idempotency)->handle(new BookingRescheduled($booking, $previousStartsAt));

    expect($sent)->toHaveCount(1);
});

test('BookingScheduler::reschedule() dispara BookingRescheduled y encola AMBOS listeners — el del cliente sigue intacto', function () {
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
    $day = CarbonImmutable::parse('2026-09-07');
    ResourceSchedule::create([
        'resource_id' => $resource->id, 'weekday' => $day->dayOfWeek,
        'start_time' => '09:00', 'end_time' => '18:00',
    ]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877']);
    $scheduler = new BookingScheduler(new AvailabilityCalculator);

    $booking = $scheduler->schedule($service, $location, $customer, $day->setTime(10, 0), $resource);
    $scheduler->reschedule($booking, $day->setTime(11, 0));

    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendBookingRescheduleNotification::class);
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendOwnerBookingRescheduleNotification::class);
});
