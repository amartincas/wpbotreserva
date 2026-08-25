<?php

use App\Application\Booking\Listeners\SendBookingCancellationNotification;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\CRM\Customer;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Domain\Scheduling\Service;
use App\Enums\BookingStatus;

test('handle() manda la notificación de cancelación con el teléfono y el mensaje correctos', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567', 'name' => 'Ana']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CANCELLED, 'cancelled_at' => now(),
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

    (new SendBookingCancellationNotification($fakeSender))->handle(new BookingCancelled($booking));

    expect($sent)->toHaveCount(1);
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($sent[0]['message'])->toContain('Corte');
    expect($sent[0]['message'])->toContain('cancelada');
});
