<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\PendingAttendanceConfirmation;
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
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
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

/**
 * Corrección de presentación de timezone: la ventana temporal (23-24h) no
 * se toca — sigue calculándose con now() del servidor, solo cambia cómo se
 * MUESTRA la fecha/hora en la plantilla. 08:30 (crudo, dentro de la
 * ventana fijada por el beforeEach) equivale a 22:30 el mismo día en
 * Asia/Tokyo (14h adelantado) — sin la conversión, el mensaje llevaría
 * "08:30", nunca "22:30". Mismo beforeEach ya existente (setTestNow sin
 * timezone explícito, coincide con config('app.timezone') — no reproduce
 * el quirk).
 */
test('el recordatorio muestra la fecha/hora en el timezone de la Organization, no en el del servidor', function () {
    $organization = upcomingReminderFixtureOrganization('wamid-upcoming-reminder-tz');
    $organization->update(['timezone' => 'Asia/Tokyo']);
    $organization = $organization->fresh();
    $booking = upcomingReminderFixtureBooking($organization, CarbonImmutable::parse('2026-08-22 08:30:00'));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($this->sent)->toHaveCount(1);
    expect($this->sent[0]['bodyParameters'])->toBe([
        'Corte de cabello',
        '22/08/2026',
        '22:30',
    ]);
});

test('una reserva a ~23.5h manda el recordatorio de confirmación de asistencia con los datos correctos', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->not->toBeNull();
    expect($this->sent)->toHaveCount(1);
    expect($this->sent[0]['type'])->toBe('template');
    expect($this->sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($this->sent[0]['templateName'])->toBe('confirmacion_asistencia_reserva');
    expect($this->sent[0]['language'])->toBe('es');
    expect($this->sent[0]['bodyParameters'])->toBe([
        'Corte de cabello',
        $booking->starts_at->format('d/m/Y'),
        $booking->starts_at->format('H:i'),
    ]);
});

test('Fase 3: un envío exitoso crea la fila PendingAttendanceConfirmation con expires_at = starts_at', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    $pending = PendingAttendanceConfirmation::where('booking_id', $booking->id)->first();
    expect($pending)->not->toBeNull();
    expect($pending->template_name)->toBe('confirmacion_asistencia_reserva');
    expect($pending->expires_at->equalTo($booking->fresh()->starts_at))->toBeTrue();
    expect($pending->declined_at)->toBeNull();
});

test('una reserva ya recordada no recibe un segundo recordatorio', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();
    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($this->sent)->toHaveCount(1);
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->count())->toBe(1);
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
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);

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
            throw new NotificationDeliveryException('plantilla no aprobada todavía');
        }

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
    app()->instance(NotificationSenderInterface::class, $failingSender);

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    expect($booking->fresh()->upcoming_reminder_sent_at)->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
});

test('Fase 3: si sendTemplate() tiene éxito pero falla la persistencia posterior, hace rollback completo (upcoming_reminder_sent_at y pending quedan como si nada hubiera pasado)', function () {
    $organization = upcomingReminderFixtureOrganization();
    $booking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    // Fila preexistente para este booking_id: el unique(booking_id) de la
    // tabla hace que el PendingAttendanceConfirmation::create() DENTRO de
    // la transacción falle de verdad (constraint real, no simulado) —
    // dispara el mismo catch que cualquier otro fallo de persistencia.
    PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'otra_fila_preexistente', 'expires_at' => now()->addDay(),
    ]);

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    // sendTemplate() sí se llamó (Meta aceptó el mensaje) — eso no se revierte,
    // es la persistencia LOCAL posterior la que se atomiza.
    expect($this->sent)->toHaveCount(1);
    // El rollback deja upcoming_reminder_sent_at en NULL — no en el valor
    // que la transacción abortada intentó escribir.
    expect($booking->fresh()->upcoming_reminder_sent_at)->toBeNull();
    // Sigue existiendo únicamente la fila preexistente (1), nunca una
    // segunda creada a medias por la transacción fallida.
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->count())->toBe(1);
});

test('Fase 3: un fallo de persistencia en una reserva no interrumpe el procesamiento de las siguientes del mismo lote', function () {
    $organization = upcomingReminderFixtureOrganization();
    // now() en el test queda fijado a una hora en punto (beforeEach) — dos
    // slots de 30 min consecutivos y alineados, sin solaparse.
    $failingBooking = upcomingReminderFixtureBooking($organization, now()->addHours(23));
    $okBooking = upcomingReminderFixtureBooking($organization, now()->addHours(23)->addMinutes(30));

    PendingAttendanceConfirmation::create([
        'booking_id' => $failingBooking->id, 'template_name' => 'otra_fila_preexistente', 'expires_at' => now()->addDay(),
    ]);

    $this->artisan('bookings:send-upcoming-reminders')->assertSuccessful();

    // Ambas reservas reciben el intento de envío (sendTemplate se llama
    // antes de que la persistencia pueda fallar).
    expect($this->sent)->toHaveCount(2);

    // La que falló: sin upcoming_reminder_sent_at, sigue con solo su fila
    // preexistente.
    expect($failingBooking->fresh()->upcoming_reminder_sent_at)->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $failingBooking->id)->count())->toBe(1);

    // La siguiente del lote se procesó normalmente pese al fallo anterior.
    expect($okBooking->fresh()->upcoming_reminder_sent_at)->not->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $okBooking->id)->exists())->toBeTrue();
});
