<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Sin esto, el comando intenta pegarle a la API real de Meta (sin
    // credenciales en test) — el try/catch de ReviewPastBookings absorbería
    // la falla en silencio en vez de dejar ver si el resto del test se
    // comporta bien. $this->sent queda disponible para los tests que
    // necesitan inspeccionar los mensajes enviados.
    $this->sent = [];
    app()->instance(NotificationSenderInterface::class, reviewPastFakeNotificationSender($this->sent));
});

function reviewPastFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            // No-op: la confirmación de reserva al crear la fixture pasa por
            // acá (SendBookingConfirmationNotification), no por el código
            // bajo prueba — solo sendTemplate() es lo que este test observa.
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'templateName', 'language', 'bodyParameters');
        }
    };
}

function reviewPastFixtureOrganization(string $phoneNumberId = 'wamid-review-past', ?string $ownerPhone = '+573009999999'): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: $ownerPhone,
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        serviceName: 'Corte de cabello',
        serviceDurationMinutes: 30,
        resourceName: 'Carlos',
        weeklySchedule: array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '00:00', endTime: '23:59'),
            range(0, 6)
        ),
    ));

    return Organization::findOrFail($result->organizationId);
}

function reviewPastFixtureBooking(Organization $organization, CarbonImmutable $startsAt): Booking
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

test('una reserva vencida sin recordatorio recibe uno, dirigido al owner_phone, y queda marcada', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->reminder_sent_at)->not->toBeNull();
    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED); // todavía no se completa, solo 2 días
});

test('una reserva ya recordada no recibe un segundo recordatorio en la siguiente corrida', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();
    $firstReminderAt = $booking->fresh()->reminder_sent_at;

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->reminder_sent_at->equalTo($firstReminderAt))->toBeTrue();
});

test('una reserva vencida hace más de 7 días se completa automáticamente y se avisa al owner', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(8)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::COMPLETED);
});

test('una reserva vencida hace menos de 7 días no se completa todavía', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(3)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED);
});

test('una reserva futura no recibe recordatorio ni se toca', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->addDays(3)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->reminder_sent_at)->toBeNull();
    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED);
});

test('una reserva ya cancelada nunca se completa por más vieja que sea', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(10)->setTime(9, 0));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::CANCELLED);
});

test('sin owner_phone configurado, no manda recordatorio pero el respaldo de 7 días igual la completa', function () {
    $organization = reviewPastFixtureOrganization();
    $organization->update(['owner_phone' => null]); // RegisterOrganizationData exige string no-nulo, se limpia después
    $booking = reviewPastFixtureBooking($organization, now()->subDays(8)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($booking->fresh()->reminder_sent_at)->toBeNull();
    expect($booking->fresh()->status)->toBe(BookingStatus::COMPLETED);
    expect($this->sent)->toBe([]);
});

test('el mensaje de recordatorio y el de auto-completado se mandan al owner_phone, no al cliente', function () {
    $organization = reviewPastFixtureOrganization();
    reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));
    reviewPastFixtureBooking($organization, now()->subDays(8)->setTime(10, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($this->sent)->toHaveCount(2);
    foreach ($this->sent as $message) {
        expect($message['toPhoneE164'])->toBe('+573009999999');
    }
    expect($this->sent[0]['templateName'])->toBe('aviso_turno_vencido');
    expect($this->sent[1]['templateName'])->toBe('turno_completado_automatico');
});
