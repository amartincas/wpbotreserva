<?php

use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function upcomingReminderFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = ['type' => 'text', 'toPhoneE164' => $toPhoneE164, 'message' => $message];
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void
        {
            $this->sent[] = compact('toPhoneE164', 'templateName', 'language', 'bodyParameters') + ['type' => 'template'];
        }

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
}

function upcomingReminderFixtureOrganization(string $phoneNumberId = 'wamid-upcoming-reminder'): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'AMC Studios',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
        resources: [new ResourceRegistrationData('Carlos', array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '00:00', endTime: '23:59'),
            range(0, 6)
        ))],
    ));

    return Organization::findOrFail($result->organizationId);
}

function upcomingReminderFixtureBooking(Organization $organization, CarbonImmutable $startsAt): Booking
{
    $result = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: $startsAt,
        resource: $organization->resources()->first(),
    ));

    return Booking::findOrFail($result->bookingId);
}

beforeEach(function () {
    $this->sent = [];
    app()->instance(NotificationSenderInterface::class, upcomingReminderFakeNotificationSender($this->sent));

    // now() real trae minutos/segundos arbitrarios — sumarle horas no cae
    // en un slot alineado a 30 min (mismo problema ya visto en otros
    // tests de este proyecto). Se fija a una hora en punto para que toda
    // la aritmética de horas caiga siempre en un horario válido.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 09:00:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('una reserva a ~23.5h manda el recordatorio por plantilla con los datos correctos', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->not->toBeNull();
    expect($this->sent)->toHaveCount(1);
    expect($this->sent[0]['type'])->toBe('template');
    expect($this->sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($this->sent[0]['templateName'])->toBe('recordatorio_reserva');
    expect($this->sent[0]['language'])->toBe('es');
    expect($this->sent[0]['bodyParameters'])->toBe([
        'Ana',
        'Corte de cabello',
        'AMC Studios',
        $booking->starts_at->format('d/m/Y'),
        $booking->starts_at->format('H:i'),
    ]);
});

test('una reserva ya recordada no recibe un segundo recordatorio', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();
    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($this->sent)->toHaveCount(1);
});

test('una reserva fuera de la ventana de 23-24h no recibe recordatorio todavía', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(48));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->toBeNull();
    expect($this->sent)->toBeEmpty();
});

test('una reserva ya pasada la ventana (menos de 23h) tampoco recibe recordatorio', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(2));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->toBeNull();
    expect($this->sent)->toBeEmpty();
});

test('una reserva cancelada dentro de la ventana no recibe recordatorio', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));
    (new App\Application\Booking\CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($this->sent)->toBeEmpty();
});

test('si el envío falla, no marca upcoming_reminder_sent_at y no interrumpe el resto del lote', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $failingSender = new class implements NotificationSenderInterface
    {
        public function send(Organization $organization, string $toPhoneE164, string $message): void {}

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void
        {
            throw new App\Application\Exceptions\NotificationDeliveryException('plantilla no aprobada todavía');
        }

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
    app()->instance(NotificationSenderInterface::class, $failingSender);

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->toBeNull();
});
