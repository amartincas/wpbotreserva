<?php

use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
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
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

test('handle() manda la notificación con el teléfono y el mensaje correctos', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567', 'name' => 'Ana']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    $sent = [];
    $fakeSender = new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sharedRef) {}

        public function send($organization, string $toPhoneE164, string $message): void
        {
            $this->sharedRef[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate($organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons($organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };

    (new SendBookingConfirmationNotification($fakeSender))->handle(new BookingConfirmed($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($sent[0]['message'])->toContain('Corte');
    expect($sent[0]['organization']->is($org))->toBeTrue();
});

/**
 * Corrección de presentación de timezone: starts_at releído de la base
 * siempre está en config('app.timezone') (America/Bogota en este proyecto)
 * — el mensaje al cliente debe mostrar la hora convertida al timezone de
 * la Organization, no la cruda. 20:00 Bogota (el literal escrito abajo,
 * interpretado tal cual al releerse) equivale a 10:00 del día siguiente en
 * Asia/Tokyo — sin la conversión, el mensaje mostraría "20:00", nunca
 * "10:00". Fecha fija, sin setTestNow, determinista.
 */
test('el mensaje muestra la hora en el timezone de la Organization, no en el del servidor', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'timezone' => 'Asia/Tokyo']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567', 'name' => 'Ana']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 20:00:00', 'ends_at' => '2026-09-07 20:30:00',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    $sent = [];
    $fakeSender = new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sharedRef) {}

        public function send($organization, string $toPhoneE164, string $message): void
        {
            $this->sharedRef[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate($organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons($organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };

    (new SendBookingConfirmationNotification($fakeSender))->handle(new BookingConfirmed($booking));

    expect($sent[0]['message'])->toContain('10:00');
    expect($sent[0]['message'])->not->toContain('20:00');
});

test('BookingScheduler dispara BookingConfirmed y el listener queda encolado (wiring end-to-end)', function () {
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
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    (new BookingScheduler(new AvailabilityCalculator))
        ->schedule($service, $location, $customer, CarbonImmutable::parse('2026-09-07 10:00'), $resource);

    Queue::assertPushed(CallQueuedListener::class, function ($job) {
        return $job->class === SendBookingConfirmationNotification::class;
    });
});
