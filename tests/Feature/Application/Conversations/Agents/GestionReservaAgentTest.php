<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Booking\RescheduleBookingCommand;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\GestionReservaAgent;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function gestionFakeDraftRepository(): ConversationDraftRepositoryInterface
{
    return new class implements ConversationDraftRepositoryInterface
    {
        private array $store = [];

        public function get(ConversationSession $session): array
        {
            return $this->store[$session->id] ?? [];
        }

        public function put(ConversationSession $session, array $draft): void
        {
            $this->store[$session->id] = $draft;
        }

        public function forget(ConversationSession $session): void
        {
            unset($this->store[$session->id]);
        }
    };
}

function gestionFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}
    };
}

/**
 * @param  string[]  $responses
 */
function gestionQueuedAi(array $responses): AiServiceInterface
{
    return new class($responses) implements AiServiceInterface
    {
        public function __construct(private array $responses) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            if ($this->responses === []) {
                throw new RuntimeException('Se llamó a la IA más veces de las esperadas por el test.');
            }

            return array_shift($this->responses);
        }
    };
}

function gestionNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

// Mismo patrón/motivo que ReservaAgentTest: horario abierto los 7 días para
// que el test no dependa de en qué día calendario corre.
function gestionFixtureOrganization(string $phoneNumberId = 'wamid-gestion'): Organization
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
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
        resources: [new ResourceRegistrationData('Carlos', array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '09:00', endTime: '17:00'),
            range(0, 6)
        ))],
    ));

    return Organization::findOrFail($result->organizationId);
}

function gestionFixtureSession(Organization $organization, string $customerPhone = '+573001234567'): ConversationSession
{
    $channel = $organization->channels()->first();

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => $customerPhone,
        'organization_id' => $organization->id,
    ]);
}

function gestionFixtureMessage(string $text, string $fromPhone = '+573001234567'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-gestion', $fromPhone, $text, now()->toImmutable());
}

function gestionFixtureBooking(Organization $organization, string $customerPhone, CarbonImmutable $startsAt): Booking
{
    $result = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: $customerPhone,
        customerName: null,
        startsAt: $startsAt,
        resource: $organization->resources()->first(),
    ));

    return Booking::findOrFail($result->bookingId);
}

function buildGestionReservaAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): GestionReservaAgent
{
    return new GestionReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        gestionFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        app(App\Domain\Booking\Contracts\ActiveBookingsFinderInterface::class),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new RescheduleBookingCommand(app(BookingSchedulerInterface::class)),
        $ai,
    );
}

test('sin reservas activas, avisa que no tiene ninguna y no llama a la IA', function () {
    $organization = gestionFixtureOrganization();
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('quiero cancelar mi turno'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('No tenés ninguna reserva');
});

test('con una sola reserva activa, la presenta directo y pregunta qué acción', function () {
    $organization = gestionFixtureOrganization();
    $booking = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('quiero ver mi turno'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('Corte de cabello');
    expect($drafts->get($session)['bookingId'])->toBe($booking->id);
    expect($drafts->get($session)['_awaiting_action'])->toBeTrue();
});

test('con varias reservas activas, las lista y espera que el cliente elija una', function () {
    $organization = gestionFixtureOrganization();
    gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(10, 0));
    gestionFixtureBooking($organization, '+573001234567', now()->addDays(2)->setTime(11, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);

    expect($sent[0]['message'])->toContain('varias reservas');
    expect($sent[0]['message'])->toContain('1)');
    expect($sent[0]['message'])->toContain('2)');
    expect($drafts->get($session)['_awaiting_booking_selection'])->toBeTrue();
});

test('reservas canceladas o completadas no cuentan como activas', function () {
    $organization = gestionFixtureOrganization();
    $old = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($old);
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);

    expect($sent[0]['message'])->toContain('No tenés ninguna reserva');
});

test('elegir "estado" responde el estado actual y limpia el draft', function () {
    $organization = gestionFixtureOrganization();
    gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('estado'), $session, $organization);

    expect($sent[1]['message'])->toContain('confirmado');
    expect($drafts->get($session))->toBe([]);
});

test('elegir "cancelar" pide confirmación antes de tocar la reserva', function () {
    $organization = gestionFixtureOrganization();
    $booking = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('cancelar'), $session, $organization);

    expect($sent[1]['message'])->toContain('Confirmás');
    expect($drafts->get($session)['_awaiting_cancel_confirmation'])->toBeTrue();
    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED);
});

test('confirmar la cancelación llama a CancelBookingCommand y limpia el draft', function () {
    $organization = gestionFixtureOrganization();
    $booking = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('cancelar'), $session, $organization);
    $agent->handle(gestionFixtureMessage('sí'), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::CANCELLED);
    expect($drafts->get($session))->toBe([]);
    expect($sent[2]['message'])->toContain('cancelé');
});

test('si no confirma la cancelación, la reserva queda intacta', function () {
    $organization = gestionFixtureOrganization();
    $booking = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('cancelar'), $session, $organization);
    $agent->handle(gestionFixtureMessage('no'), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED);
    expect($drafts->get($session))->toBe([]);
});

test('elegir "reprogramar" pide la nueva fecha y luego ofrece horarios', function () {
    $organization = gestionFixtureOrganization();
    gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $newDate = now()->addDays(3)->toDateString();
    $agent = buildGestionReservaAgent($drafts, $sent, gestionQueuedAi([$newDate]));

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('reprogramar'), $session, $organization);
    $agent->handle(gestionFixtureMessage('para el jueves'), $session, $organization);

    expect($sent[1]['message'])->toContain('qué día');
    expect($sent[2]['message'])->toContain('horarios disponibles');
    expect($drafts->get($session)['_awaiting_slot_selection'])->toBeTrue();
});

test('confirmar la reprogramación llama a RescheduleBookingCommand y mueve la reserva', function () {
    $organization = gestionFixtureOrganization();
    $booking = gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization);
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $newDate = now()->addDays(3)->toDateString();
    $agent = buildGestionReservaAgent($drafts, $sent, gestionQueuedAi([$newDate]));

    $agent->handle(gestionFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionFixtureMessage('reprogramar'), $session, $organization);
    $agent->handle(gestionFixtureMessage('para el jueves'), $session, $organization);
    $agent->handle(gestionFixtureMessage('1'), $session, $organization);
    $agent->handle(gestionFixtureMessage('sí'), $session, $organization);

    $fresh = $booking->fresh();
    expect($fresh->id)->toBe($booking->id);
    expect($fresh->starts_at->toDateString())->toBe($newDate);
    expect($fresh->status)->toBe(BookingStatus::CONFIRMED);
    expect($drafts->get($session))->toBe([]);
    expect($sent[4]['message'])->toContain('reprogramado');
});

test('mensajes de gestión de reservas de otro cliente nunca ven las reservas ajenas', function () {
    $organization = gestionFixtureOrganization();
    gestionFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $session = gestionFixtureSession($organization, '+573009998877');
    $drafts = gestionFakeDraftRepository();
    $sent = [];
    $agent = buildGestionReservaAgent($drafts, $sent, gestionNeverCalledAi());

    $agent->handle(gestionFixtureMessage('hola', '+573009998877'), $session, $organization);

    expect($sent[0]['message'])->toContain('No tenés ninguna reserva');
});
