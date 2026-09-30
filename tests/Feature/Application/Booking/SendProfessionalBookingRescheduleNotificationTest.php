<?php

use App\Application\Booking\Listeners\SendBookingRescheduleNotification;
use App\Application\Booking\Listeners\SendProfessionalBookingRescheduleNotification;
use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Events\BookingRescheduled;
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

function professionalRescheduleFakeSender(array &$sent, ?Throwable $throwOnNextCall = null): NotificationSenderInterface
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

function professionalRescheduleFixtureBooking(): Booking
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
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 21:00', 'ends_at' => '2026-10-05 21:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    return $booking->fresh(['bookingResources']);
}

function buildProfessionalRescheduleListener(NotificationSenderInterface $sender): SendProfessionalBookingRescheduleNotification
{
    return new SendProfessionalBookingRescheduleNotification(
        new ProfessionalRecipientResolver,
        $sender,
        new ProfessionalNotificationIdempotency,
    );
}

test('implementa ShouldQueueAfterCommit', function () {
    $sent = [];
    expect(new SendProfessionalBookingRescheduleNotification(
        new ProfessionalRecipientResolver, professionalRescheduleFakeSender($sent), new ProfessionalNotificationIdempotency,
    ))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('handle() manda sendTemplate() al profesional con horario anterior y nuevo correctos', function () {
    $booking = professionalRescheduleFixtureBooking();
    $previousStartsAt = CarbonImmutable::parse('2026-10-05 15:00'); // horario anterior, distinto al starts_at actual del booking
    $sent = [];

    buildProfessionalRescheduleListener(professionalRescheduleFakeSender($sent))
        ->handle(new BookingRescheduled($booking, $previousStartsAt));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573005556677');
    expect($sent[0]['toPhoneE164'])->not->toBe($booking->organization->owner_phone);
    expect($sent[0]['templateName'])->toBe('reserva_modificada_profesional');
    expect($sent[0]['language'])->toBe('es');
    // 'l d/m/Y H:i' en la timezone de la Organization (America/Bogota, igual
    // que APP_TIMEZONE — conversión no-op en este caso, ver test de timezone
    // dedicado en SendProfessionalBookingConfirmationNotificationTest).
    expect($sent[0]['bodyParameters'][0])->toBe('Corte');
    expect($sent[0]['bodyParameters'][1])->toBe('Ana');
    expect($sent[0]['bodyParameters'][2])->toContain('15:00'); // anterior
    expect($sent[0]['bodyParameters'][3])->toContain('21:00'); // nuevo
    expect($sent[0]['bodyParameters'][2])->not->toBe($sent[0]['bodyParameters'][3]);
});

test('repetir exactamente el mismo evento de reprogramación no manda una segunda notificación', function () {
    $booking = professionalRescheduleFixtureBooking();
    $previousStartsAt = CarbonImmutable::parse('2026-10-05 15:00');
    $sent = [];
    $idempotency = new ProfessionalNotificationIdempotency;

    $listener = new SendProfessionalBookingRescheduleNotification(new ProfessionalRecipientResolver, professionalRescheduleFakeSender($sent), $idempotency);

    $listener->handle(new BookingRescheduled($booking, $previousStartsAt));
    $listener->handle(new BookingRescheduled($booking, $previousStartsAt)); // mismo evento exacto (ej. reintento de cola)

    expect($sent)->toHaveCount(1);
});

test('dos reprogramaciones legítimas y distintas de la MISMA reserva generan dos notificaciones', function () {
    $booking = professionalRescheduleFixtureBooking(); // starts_at real: 2026-10-05 21:00
    $sent = [];
    $idempotency = new ProfessionalNotificationIdempotency;
    $listener = new SendProfessionalBookingRescheduleNotification(new ProfessionalRecipientResolver, professionalRescheduleFakeSender($sent), $idempotency);

    // 1ra reprogramación: de 15:00 a 21:00 (el starts_at actual del fixture).
    $listener->handle(new BookingRescheduled($booking, CarbonImmutable::parse('2026-10-05 15:00')));

    // 2da reprogramación real: el booking se mueve de nuevo, esta vez a las 18:00.
    $booking->update(['starts_at' => '2026-10-06 18:00', 'ends_at' => '2026-10-06 18:30']);
    $booking = $booking->fresh(['bookingResources']);
    $listener->handle(new BookingRescheduled($booking, CarbonImmutable::parse('2026-10-05 21:00')));

    expect($sent)->toHaveCount(2);
    expect($sent[0]['bodyParameters'][3])->toContain('21:00');
    expect($sent[1]['bodyParameters'][3])->toContain('18:00');
});

test('si el sender falla, no se marca como enviado', function () {
    $booking = professionalRescheduleFixtureBooking();
    $previousStartsAt = CarbonImmutable::parse('2026-10-05 15:00');
    $idempotency = new ProfessionalNotificationIdempotency;

    $sent = [];
    $failingListener = new SendProfessionalBookingRescheduleNotification(
        new ProfessionalRecipientResolver,
        professionalRescheduleFakeSender($sent, new NotificationDeliveryException('Meta caído')),
        $idempotency,
    );

    expect(fn () => $failingListener->handle(new BookingRescheduled($booking, $previousStartsAt)))
        ->toThrow(NotificationDeliveryException::class);
    expect($sent)->toBeEmpty();

    $retryListener = new SendProfessionalBookingRescheduleNotification(new ProfessionalRecipientResolver, professionalRescheduleFakeSender($sent), $idempotency);
    $retryListener->handle(new BookingRescheduled($booking, $previousStartsAt));

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
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => '+573005556677',
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
    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendProfessionalBookingRescheduleNotification::class);
});
