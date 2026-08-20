<?php

use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\ReservaAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function reservaFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function reservaFakeNotificationSender(array &$sent): NotificationSenderInterface
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
function reservaQueuedAi(array $responses): AiServiceInterface
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

function reservaNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

/**
 * Registra una organización real (vía RegisterOrganizationCommand) con
 * disponibilidad los 7 días de la semana — evita que el test sea sensible
 * a en qué día de la semana corre realmente ("mañana" cualquier día tiene
 * agenda abierta). weekday va de 0 (domingo) a 6 (sábado) — la convención
 * real de resource_schedules.weekday (migración Hito 1) y la que consume
 * AvailabilityCalculator, NO 1-7 ISO (bug real encontrado y corregido en
 * el Hito 8: con 1-7, domingo quedaba sin ninguna fila que lo cubriera).
 */
function reservaFixtureOrganization(string $phoneNumberId = 'wamid-reserva'): Organization
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
        serviceName: 'Corte de cabello',
        serviceDurationMinutes: 30,
        resourceName: 'Carlos',
        weeklySchedule: array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '09:00', endTime: '17:00'),
            range(0, 6)
        ),
    ));

    return Organization::findOrFail($result->organizationId);
}

function reservaFixtureSession(Organization $organization): ConversationSession
{
    $channel = $organization->channels()->first();

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization->id,
    ]);
}

function reservaFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-reserva', '+573001234567', $text, now()->toImmutable());
}

function buildReservaAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): ReservaAgent
{
    return new ReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        reservaFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        new CreateBookingCommand(app(BookingSchedulerInterface::class)),
        $ai,
    );
}

test('el primer mensaje del flujo pregunta la fecha, sin llamar a la IA', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $agent = buildReservaAgent($drafts, $sent, reservaNeverCalledAi());

    $agent->handle(reservaFixtureMessage('hola quiero un turno'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('qué día');
    expect($drafts->get($session)['_started'])->toBeTrue();
});

test('si la fecha y el nombre ya están respondidos pero todavía no se ofrecieron horarios, los ofrece en vez de romper', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $tomorrow = now()->addDay();
    $drafts->put($session, ['_started' => true, 'date' => $tomorrow, 'customerName' => 'Ana']);
    $sent = [];
    $agent = buildReservaAgent($drafts, $sent, reservaNeverCalledAi());

    $agent->handle(reservaFixtureMessage('cualquier cosa'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('horarios disponibles');
    expect($drafts->get($session)['_awaiting_slot_selection'])->toBeTrue();
});

test('después de la fecha, pregunta a nombre de quién es la reserva antes de ofrecer horarios', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow]));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('nombre de quién');
    expect($drafts->get($session)['date'])->not->toBeNull();
    expect($drafts->get($session))->not->toHaveKey('customerName');
});

test('con disponibilidad, ofrece los horarios encontrados y queda esperando selección', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('horarios disponibles');
    expect($sent[2]['message'])->toContain('1)');
    expect($drafts->get($session)['_awaiting_slot_selection'])->toBeTrue();
    expect($drafts->get($session)['_candidateSlots'])->not->toBeEmpty();
});

test('el domingo tiene disponibilidad (regresión Hito 8: WeeklyScheduleFieldExtractor guardaba weekday en convención ISO, distinta de la que consulta AvailabilityCalculator)', function () {
    // Fecha explícita, no "mañana": el bug real solo se manifestaba en
    // domingo (weekday=0) — un test basado en "el día que sea que corra
    // esto" es precisamente cómo quedó invisible tanto tiempo.
    $nextSunday = now()->next(CarbonImmutable::SUNDAY);
    expect($nextSunday->dayOfWeek)->toBe(0); // confirma la convención antes de confiar en el resto del test

    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$nextSunday->toDateString(), 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('el domingo'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('horarios disponibles');
});

test('sin disponibilidad ese día, pide otra fecha y permite volver a intentar', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $targetDate = now()->addDays(10)->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$targetDate, 'Ana']));

    // El horario semanal de la fixture cubre los 7 días, así que "sin
    // disponibilidad" se fuerza agotando la capacidad: se reserva contra el
    // dominio, directamente, cada slot que AvailabilityCalculator encuentra
    // para ese día antes de que el Agent lo consulte.
    $service = $organization->services()->first();
    $location = $organization->locations()->first();
    $resource = $organization->resources()->first();
    $availability = app(AvailabilityCalculatorInterface::class);
    $date = CarbonImmutable::parse($targetDate);
    $slots = $availability->availableSlots($service, $location, $date, $resource);
    $createBooking = new CreateBookingCommand(app(BookingSchedulerInterface::class));
    foreach ($slots as $slot) {
        $createBooking->handle(new CreateBookingData(
            organization: $organization,
            location: $location,
            service: $service,
            customerPhone: '+573005555555',
            customerName: null,
            startsAt: $slot->range->start,
            resource: $resource,
        ));
    }

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('en 5 años'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('No hay turnos disponibles');
    expect($drafts->get($session))->not->toHaveKey('date');
    expect($drafts->get($session)['customerName'])->toBe('Ana'); // no se pierde al pedir otra fecha
});

test('una selección de horario inválida re-pregunta sin avanzar', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('la opción que sea'), $session, $organization);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('No entendí la opción');
    expect($drafts->get($session)['_awaiting_slot_selection'])->toBeTrue();
});

test('una selección válida pide confirmación', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('1'), $session, $organization);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('Confirmás el turno');
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect(Booking::count())->toBe(0);
});

test('al confirmar con sí, crea la reserva con el nombre del cliente, limpia el draft y el Intent, y confirma al cliente', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $sessions = new EloquentConversationSessionRepository;
    $sessions->recordIntent($session, Intent::Reserva);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('1'), $session, $organization);
    $agent->handle(reservaFixtureMessage('sí'), $session, $organization);

    expect(Booking::count())->toBe(1);
    expect(Booking::first()->customer->name)->toBe('Ana');
    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('quedó confirmado');
});

test('si la confirmación no es un sí, vuelve a pedir confirmación sin crear la reserva', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('1'), $session, $organization);
    $agent->handle(reservaFixtureMessage('tal vez'), $session, $organization);

    expect(Booking::count())->toBe(0);
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
});

test('si el horario se ocupa justo antes de confirmar, avisa y reinicia el draft en vez de crear una reserva inconsistente', function () {
    $organization = reservaFixtureOrganization();
    $session = reservaFixtureSession($organization);
    $drafts = reservaFakeDraftRepository();
    $sent = [];
    $tomorrow = now()->addDay()->toDateString();
    $agent = buildReservaAgent($drafts, $sent, reservaQueuedAi([$tomorrow, 'Ana']));

    $agent->handle(reservaFixtureMessage('hola'), $session, $organization);
    $agent->handle(reservaFixtureMessage('mañana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('Ana'), $session, $organization);
    $agent->handle(reservaFixtureMessage('1'), $session, $organization);

    // Alguien más ocupa exactamente ese horario antes de que el cliente confirme.
    $chosenSlot = CarbonImmutable::parse($drafts->get($session)['chosenSlot']);
    (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573005555555',
        customerName: null,
        startsAt: $chosenSlot,
        resource: $organization->resources()->first(),
    ));

    $agent->handle(reservaFixtureMessage('sí'), $session, $organization);

    expect(Booking::count())->toBe(1); // solo la del otro cliente
    expect($sent[4]['message'])->toContain('se ocupó');
    expect($drafts->get($session))->toBe([]);
});
