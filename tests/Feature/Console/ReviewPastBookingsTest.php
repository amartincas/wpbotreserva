<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // B6: los avisos al dueño salen por el CENTRAL, vía OwnerNotifier real —
    // se simula solo el cliente de Meta (sin credenciales en test), que
    // además registra por qué Channel salió cada mensaje. $this->sent queda
    // disponible para los tests que necesitan inspeccionar lo enviado.
    $this->sent = [];
    $this->central = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-review-past-central',
        'status' => ChannelStatus::ACTIVE,
    ]);
    app()->instance(ChannelClientInterface::class, reviewPastFakeChannelClient($this->sent));
});

function reviewPastFakeChannelClient(array &$sent): ChannelClientInterface
{
    return new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void {}

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'templateName' => $templateName, 'language' => $language, 'bodyParameters' => $bodyParameters];
        }

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
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
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: [new ResourceRegistrationData('Carlos', array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '00:00', endTime: '23:59'),
            range(0, 6)
        ))],
    ));
    // B5: el registro ya no vincula ningún Channel — el BUSINESS del negocio
    // se conecta aparte (B9); acá se vincula a mano para el fixture.
    $channel->organizations()->attach($result->organizationId, ['is_primary' => true]);

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

/**
 * Corrección de presentación de timezone: la ventana temporal (vencida
 * hace 2 días) no se toca, solo cómo se muestra la fecha/hora en el
 * recordatorio. 9am (crudo, tiempo real) equivale a 23:00 del mismo día en
 * Asia/Tokyo (14h adelantado) — sin la conversión, el mensaje llevaría
 * "09:00", nunca "23:00". Tiempo real, sin setTestNow.
 */
test('el recordatorio al dueño muestra la fecha/hora en el timezone de la Organization, no en el del servidor', function () {
    $organization = reviewPastFixtureOrganization('wamid-review-past-tz');
    $organization->update(['timezone' => 'Asia/Tokyo']);
    $organization = $organization->fresh();
    reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($this->sent)->toHaveCount(1);
    expect($this->sent[0]['bodyParameters'][2])->toContain('23:00');
    expect($this->sent[0]['bodyParameters'][2])->not->toContain('09:00');
});

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

test('B6: el recordatorio y el aviso de auto-completado salen por el CENTRAL, nunca por el BUSINESS de la Organization', function () {
    $organization = reviewPastFixtureOrganization();
    reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));
    reviewPastFixtureBooking($organization, now()->subDays(8)->setTime(10, 0));

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($this->sent)->toHaveCount(2);
    foreach ($this->sent as $message) {
        expect($message['channel']->is($this->central))->toBeTrue();
        expect($message['channel']->is($organization->channels()->first()))->toBeFalse();
    }
});

test('B6: sin CENTRAL activo no se envía ni se marca el recordatorio — se reintenta en la próxima corrida, sin duplicar', function () {
    $organization = reviewPastFixtureOrganization();
    $booking = reviewPastFixtureBooking($organization, now()->subDays(2)->setTime(9, 0));
    $this->central->update(['status' => ChannelStatus::DISCONNECTED]);

    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($this->sent)->toBe([]);
    expect($booking->fresh()->reminder_sent_at)->toBeNull();

    $this->central->update(['status' => ChannelStatus::ACTIVE]);
    $this->artisan('bookings:review-past')->assertSuccessful();
    $this->artisan('bookings:review-past')->assertSuccessful();

    expect($this->sent)->toHaveCount(1);
    expect($booking->fresh()->reminder_sent_at)->not->toBeNull();
});
