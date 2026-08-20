<?php

use App\Application\Booking\Listeners\SendBookingRescheduleNotification;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\CRM\Customer;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Domain\Scheduling\Service;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

test('handle() manda la notificación de reprogramación con la fecha anterior y la nueva', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567', 'name' => 'Ana']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 11:00', 'ends_at' => '2026-09-07 11:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);
    $previousStartsAt = CarbonImmutable::parse('2026-09-07 10:00');

    $sent = [];
    $fakeSender = new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sharedRef) {}

        public function send($organization, string $toPhoneE164, string $message): void
        {
            $this->sharedRef[] = compact('organization', 'toPhoneE164', 'message');
        }
    };

    (new SendBookingRescheduleNotification($fakeSender))->handle(new BookingRescheduled($booking, $previousStartsAt));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($sent[0]['message'])->toContain('Corte');
    expect($sent[0]['message'])->toContain('10:00');
    expect($sent[0]['message'])->toContain('11:00');
});
