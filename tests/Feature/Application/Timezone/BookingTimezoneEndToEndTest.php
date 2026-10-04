<?php

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\ConfirmBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Booking\MarkBookingNoShowCommand;
use App\Application\Booking\RescheduleBookingCommand;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\AdminCommandAgent;
use App\Application\Conversations\Agents\AgendaProfesionalAgent;
use App\Application\Conversations\Agents\BookingChoiceAgent;
use App\Application\Conversations\Agents\GestionReservaAgent;
use App\Application\Conversations\Agents\ReservaAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

/**
 * Corrección transversal de timezone — pruebas de extremo a extremo que
 * reproducen el flujo REAL (Agent → Command → BookingScheduler → DB →
 * consulta), nunca fabrican starts_at directo con un Carbon ya zonificado.
 *
 * Deliberadamente sin CarbonImmutable::setTestNow(): un congelamiento de
 * reloj combinado con una relectura de un atributo Eloquent datetime
 * produjo, en este entorno, una interpretación de timezone inconsistente al
 * releer el modelo (verificado de forma aislada, específico del
 * congelamiento — nunca ocurre en producción real, donde no hay reloj
 * congelado). Los escenarios de borde de medianoche/transición de fecha ya
 * están cubiertos de forma determinista y sin ese riesgo en
 * CalendarDayRangeTest y AgendaDateResolverTest (unitarios, sin Eloquent de
 * por medio). Acá se usa tiempo real con offsets grandes (+10 días o más),
 * mismo patrón que el resto de los test files de este proyecto.
 */
function tzE2eFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function tzE2eFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['toPhoneE164' => $toPhoneE164, 'message' => $bodyText];
        }
    };
}

function tzE2eFakeReplier(array &$sent): ConversationReplier
{
    return new ConversationReplier(new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $message];
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $bodyText, 'buttons' => $buttons];
        }
    });
}

function tzE2eQueuedAi(array $responses): AiServiceInterface
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

/**
 * Organización con timezone explícito (a diferencia de los fixtures de
 * otros test files, que siempre usan el default) — abre disponibilidad los
 * 7 días para no depender de qué día de la semana cae la fecha elegida.
 */
function tzE2eFixtureOrganization(string $timezone, string $phoneNumberId): Organization
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
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: [new ResourceRegistrationData(
            'Carlos',
            array_map(fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '00:00', endTime: '23:59'), range(0, 6)),
        )],
    ));
    // B5: el registro ya no vincula ningún Channel — el BUSINESS del negocio
    // se conecta aparte (B9); acá se vincula a mano para el fixture.
    $channel->organizations()->attach($result->organizationId, ['is_primary' => true]);

    $organization = Organization::findOrFail($result->organizationId);
    $organization->update(['timezone' => $timezone]);

    return $organization->fresh();
}

/**
 * Corrección de timezone en disponibilidad (BookingScheduler): variante de
 * tzE2eFixtureOrganization con horario ACOTADO (nunca 24h) — antes del fix,
 * la re-verificación interna de BookingScheduler rechazaba con
 * SlotNoLongerAvailableException cualquier horario cuyo desfase lo sacara
 * de la ventana reinterpretada en config('app.timezone'). Recorre el flujo
 * real completo (ReservaAgent → CreateBookingCommand → BookingScheduler →
 * AvailabilityCalculator → DB), no solo BookingScheduler aislado.
 */
function tzE2eFixtureOrganizationBoundedHours(string $timezone, string $phoneNumberId, string $startTime = '09:00', string $endTime = '17:00'): Organization
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
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: [new ResourceRegistrationData(
            'Carlos',
            array_map(fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: $startTime, endTime: $endTime), range(0, 6)),
        )],
    ));
    // B5: el registro ya no vincula ningún Channel — el BUSINESS del negocio
    // se conecta aparte (B9); acá se vincula a mano para el fixture.
    $channel->organizations()->attach($result->organizationId, ['is_primary' => true]);

    $organization = Organization::findOrFail($result->organizationId);
    $organization->update(['timezone' => $timezone]);

    return $organization->fresh();
}

function tzE2eFixtureSession(Organization $organization, string $customerPhone = '+573001234567'): ConversationSession
{
    return ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => $customerPhone,
        'organization_id' => $organization->id,
    ]);
}

function tzE2eFixtureMessage(string $text, string $fromPhone = '+573001234567'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-tz-e2e', $fromPhone, $text, now()->toImmutable());
}

function tzE2eReservaAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): ReservaAgent
{
    return new ReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        tzE2eFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        new CreateBookingCommand(app(BookingSchedulerInterface::class)),
        $ai,
    );
}

function tzE2eGestionReservaAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): GestionReservaAgent
{
    return new GestionReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        tzE2eFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        app(ActiveBookingsFinderInterface::class),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new RescheduleBookingCommand(app(BookingSchedulerInterface::class)),
        $ai,
    );
}

function tzE2eAgendaProfesionalAgent(array &$sent): AgendaProfesionalAgent
{
    return new AgendaProfesionalAgent(
        new AgendaDateResolver,
        new AgendaQueryService,
        tzE2eFakeReplier($sent),
        app(BotMessageRepository::class),
        new EloquentConversationSessionRepository,
    );
}

function tzE2eAdminCommandAgent(array &$sent): AdminCommandAgent
{
    return new AdminCommandAgent(
        tzE2eFakeReplier($sent),
        new EloquentConversationSessionRepository,
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new ConfirmBookingCommand(app(BookingSchedulerInterface::class)),
        new MarkBookingNoShowCommand(app(BookingSchedulerInterface::class)),
        new AgendaQueryService,
    );
}

test('creación real vía ReservaAgent: el starts_at persistido representa el instante correcto en el timezone de la Organization', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-create');
    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];

    $targetDate = CarbonImmutable::now('Asia/Tokyo')->addDays(15)->toDateString();
    $agent = tzE2eReservaAgent($drafts, $sent, tzE2eQueuedAi([$targetDate, 'Ana']));

    $agent->handle(tzE2eFixtureMessage('hola'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('en 15 días'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('Ana'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('1'), $session, $organization); // primer slot ofrecido
    $agent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    expect(Booking::count())->toBe(1);
    $booking = Booking::first();

    // El instante persistido, visto en el timezone de la Organization, tiene
    // que caer en $targetDate (nunca en otro día, que sería el efecto del
    // bug original de usar el timezone del servidor para interpretar la
    // fecha pedida).
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($targetDate);
});

test('reprogramación real vía GestionReservaAgent: el nuevo starts_at persistido representa el instante correcto en timezone de la Organization', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-reschedule');
    $resource = $organization->resources()->first();

    $original = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: CarbonImmutable::now('Asia/Tokyo')->addDays(12)->setTime(9, 0),
        resource: $resource,
    ));
    $booking = Booking::findOrFail($original->bookingId);

    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];
    $newDate = CarbonImmutable::now('Asia/Tokyo')->addDays(20)->toDateString();
    $agent = tzE2eGestionReservaAgent($drafts, $sent, tzE2eQueuedAi([$newDate]));

    $agent->handle(tzE2eFixtureMessage('quiero reprogramar mi turno'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('reprogramar'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('en 20 días'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('1'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    $booking = $booking->fresh();
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($newDate);
});

/**
 * Nota (corrección DateFieldExtractor/ruta IA): este test consultaba antes
 * con una fecha explícita ("¿cuántas citas tengo el dd/mm/aaaa?"), vía
 * AgendaDateResolver::resolveNumericDate() — que tiene el MISMO defecto
 * recién corregido en DateFieldExtractor (createFromDate() sin timezone
 * explícito), pero en un archivo fuera del alcance autorizado de esta
 * ronda (ver hallazgo en el reporte final). Antes de esta ronda, ambos
 * defectos (DateFieldExtractor al crear + AgendaDateResolver al consultar)
 * coincidían por casualidad en la misma etiqueta equivocada y se
 * cancelaban entre sí — el test pasaba por dos razones incorrectas que se
 * anulaban, no porque el timezone estuviera bien resuelto en ningún punto.
 * Al corregir solo uno de los dos (DateFieldExtractor, lo autorizado acá),
 * la cancelación desaparece y el test rompe. Se cambia la consulta a
 * "mañana" (ruta de AgendaDateResolver ya correcta, now($organization->timezone))
 * para seguir probando lo mismo sin depender del punto no corregido.
 */
test('agenda profesional después de creación real: encuentra la reserva en la fecha correcta', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-agenda-create');
    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];
    $targetDate = CarbonImmutable::now('Asia/Tokyo')->addDay()->toDateString();
    $reservaAgent = tzE2eReservaAgent($drafts, $sent, tzE2eQueuedAi([$targetDate, 'Ana']));

    $reservaAgent->handle(tzE2eFixtureMessage('hola'), $session, $organization);
    $reservaAgent->handle(tzE2eFixtureMessage('mañana'), $session, $organization);
    $reservaAgent->handle(tzE2eFixtureMessage('Ana'), $session, $organization);
    $reservaAgent->handle(tzE2eFixtureMessage('1'), $session, $organization);
    $reservaAgent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    expect(Booking::count())->toBe(1);

    $agendaSent = [];
    $agendaAgent = tzE2eAgendaProfesionalAgent($agendaSent);
    // B7: la agenda la consulta el owner (owner_phone), no el profesional.
    $agendaSession = tzE2eFixtureSession($organization, '+573009999999');

    $agendaAgent->handle(tzE2eFixtureMessage('¿cuántas citas tengo mañana?', '+573009999999'), $agendaSession, $organization);

    $label = CarbonImmutable::parse($targetDate)->format('d/m/Y');
    expect($agendaSent[0]['message'])->toBe("Tenés 1 cita para {$label}.");
});

/**
 * Corrección AgendaDateResolver/timezone: E2E obligatorio que ejercita
 * específicamente la ruta de FECHA EXPLÍCITA ("¿qué reservas tengo el
 * dd/mm/aaaa?"), ya corregida esta ronda — a diferencia de los 2 tests de
 * arriba, que deliberadamente la evitan usando "mañana". La reserva se
 * crea directo (sin pasar por DateFieldExtractor, para aislar la
 * verificación a AgendaDateResolver/AgendaProfesionalAgent únicamente).
 */
test('agenda profesional con fecha EXPLÍCITA (dd/mm/aaaa) y timezone no-default: encuentra la reserva correcta', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-agenda-explicit');
    $resource = $organization->resources()->first();
    $targetDate = CarbonImmutable::now('Asia/Tokyo')->addDays(18)->setTime(10, 0);

    (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: $targetDate,
        resource: $resource,
    ));

    $agendaSent = [];
    $agendaAgent = tzE2eAgendaProfesionalAgent($agendaSent);
    // B7: la agenda la consulta el owner (owner_phone), no el profesional.
    $agendaSession = tzE2eFixtureSession($organization, '+573009999999');
    $label = $targetDate->format('d/m/Y');

    $agendaAgent->handle(tzE2eFixtureMessage("¿qué reservas tengo el {$label}?", '+573009999999'), $agendaSession, $organization);

    expect($agendaSent[0]['message'])->toContain('10:00');
    expect($agendaSent[0]['message'])->not->toContain('No tenés citas');
});

/**
 * Ver nota del test anterior: la consulta se cambia a "mañana" (ruta ya
 * correcta de AgendaDateResolver) en vez de una fecha explícita (ruta con
 * el mismo defecto que DateFieldExtractor, fuera de alcance de esta
 * ronda). La comparación "no en la fecha original" se hace directo sobre
 * el campo persistido, sin depender de una segunda consulta de agenda.
 */
test('agenda profesional después de reprogramación real: la encuentra en la nueva fecha, no en la original', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-agenda-reschedule');
    $resource = $organization->resources()->first();

    $original = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: CarbonImmutable::now('Asia/Tokyo')->addDays(12)->setTime(9, 0),
        resource: $resource,
    ));
    $booking = Booking::findOrFail($original->bookingId);
    $originalDate = CarbonImmutable::now('Asia/Tokyo')->addDays(12)->toDateString();

    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];
    $newDate = CarbonImmutable::now('Asia/Tokyo')->addDay()->toDateString();
    $gestionAgent = tzE2eGestionReservaAgent($drafts, $sent, tzE2eQueuedAi([$newDate]));

    $gestionAgent->handle(tzE2eFixtureMessage('quiero reprogramar mi turno'), $session, $organization);
    $gestionAgent->handle(tzE2eFixtureMessage('reprogramar'), $session, $organization);
    $gestionAgent->handle(tzE2eFixtureMessage('mañana'), $session, $organization);
    $gestionAgent->handle(tzE2eFixtureMessage('1'), $session, $organization);
    $gestionAgent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    $agendaSent = [];
    $agendaAgent = tzE2eAgendaProfesionalAgent($agendaSent);
    // B7: la agenda la consulta el owner (owner_phone), no el profesional.
    $agendaSession = tzE2eFixtureSession($organization, '+573009999999');

    $agendaAgent->handle(tzE2eFixtureMessage('¿qué reservas tengo mañana?', '+573009999999'), $agendaSession, $organization);
    // Fase 5: el detalle de agenda ya no muestra el id de la reserva (solo
    // hora + nombre del cliente) — 'Ana' identifica la reserva sin ambigüedad
    // porque es la única de este test.
    expect($agendaSent[0]['message'])->toContain('Ana');

    // Nunca quedó en la fecha original: el campo persistido ya no coincide.
    $booking = $booking->fresh();
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->not->toBe($originalDate);
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($newDate);
});

test('"reservas hoy" del dueño (AdminCommandAgent) con Organization en timezone distinto al servidor, muestra la hora en timezone de la Organization', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-admin');
    $resource = $organization->resources()->first();

    $startsAt = CarbonImmutable::now('Asia/Tokyo')->setTime(10, 0);
    // Si "hoy" en Tokio ya pasó de las 10:00, usa mañana — evita flakiness
    // por hora real de ejecución sin necesitar congelar el reloj.
    if ($startsAt->isPast()) {
        $startsAt = $startsAt->addDay();
    }

    (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: $startsAt,
        resource: $resource,
    ));

    $session = tzE2eFixtureSession($organization, '+573009999999');
    $sent = [];
    $agent = tzE2eAdminCommandAgent($sent);
    $command = $startsAt->toDateString() === CarbonImmutable::now('Asia/Tokyo')->toDateString() ? 'reservas hoy' : 'reservas '.$startsAt->format('d/m/Y');

    $agent->handle(tzE2eFixtureMessage($command, '+573009999999'), $session, $organization);

    // La hora mostrada debe ser la local de Tokio (10:00), nunca la
    // equivalente en el timezone del servidor.
    expect($sent[0]['message'])->toContain('10:00');
});

test('disponibilidad: una reserva real ocupa correctamente su slot para una Organization en timezone distinto al servidor', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-availability');
    $resource = $organization->resources()->first();
    $service = $organization->services()->first();
    $targetDay10am = CarbonImmutable::now('Asia/Tokyo')->addDays(10)->setTime(10, 0);

    (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $service,
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: $targetDay10am,
        resource: $resource,
    ));

    $availability = app(AvailabilityCalculatorInterface::class);
    $slots = $availability->availableSlots($service, $organization->locations()->first(), $targetDay10am->startOfDay(), $resource);

    // El slot de las 10:00 (ya ocupado) no debe aparecer como libre.
    $occupied = $slots->filter(fn ($slot) => $slot->range->start->setTimezone('Asia/Tokyo')->format('H:i') === '10:00');
    expect($occupied)->toBeEmpty();

    // Un slot en otro horario del mismo día sí debe seguir libre.
    $free = $slots->filter(fn ($slot) => $slot->range->start->setTimezone('Asia/Tokyo')->format('H:i') === '11:00');
    expect($free)->not->toBeEmpty();
});

test('BookingChoiceAgent: el prellenado de fecha usa el timezone de la Organization, no el del servidor', function () {
    $organization = tzE2eFixtureOrganization('Asia/Tokyo', 'wamid-tz-choice');
    $resource = $organization->resources()->first();

    (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: CarbonImmutable::now('Asia/Tokyo')->addDays(8)->setTime(9, 0),
        resource: $resource,
    ));

    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];
    $targetDate = CarbonImmutable::now('Asia/Tokyo')->addDays(15)->toDateString();
    $agent = new BookingChoiceAgent($drafts, new EloquentConversationSessionRepository, tzE2eFakeNotificationSender($sent), tzE2eQueuedAi([$targetDate]));

    $agent->handle(tzE2eFixtureMessage('quiero una reserva nueva en 15 días'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('nueva'), $session, $organization);

    expect($drafts->get($session)['date']->toDateString())->toBe($targetDate);
});

/**
 * Corrección de timezone en disponibilidad — el caso que antes rompía:
 * horario ACOTADO (no 24h), Organization en timezone no-default. Prueba el
 * flujo real completo, no BookingScheduler aislado.
 */
test('creación real vía ReservaAgent con Resource de horario acotado (09:00-17:00) y timezone no-default: acepta el slot ofrecido', function () {
    $organization = tzE2eFixtureOrganizationBoundedHours('Asia/Tokyo', 'wamid-tz-bounded-create', '09:00', '17:00');
    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];

    // "el jueves" (no "en 15 días"): resuelve por la vía DETERMINISTA de
    // DateFieldExtractor (resolveNextWeekday(), ya usa $this->timezone
    // correctamente) — deliberadamente NO se usa una fecha que dependa de
    // la vía de IA (createFromFormat('Y-m-d', $response) sin timezone
    // explícito, un bug real y distinto encontrado durante esta
    // implementación, ver reporte final; no se toca DateFieldExtractor en
    // esta ronda).
    $targetDate = CarbonImmutable::now('Asia/Tokyo')->startOfDay()->next(4)->toDateString(); // 4 = jueves
    $agent = tzE2eReservaAgent($drafts, $sent, tzE2eQueuedAi(['Ana']));

    $agent->handle(tzE2eFixtureMessage('hola'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('el jueves'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('Ana'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('1'), $session, $organization); // primer slot ofrecido, dentro de 09-17 Tokio
    $agent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    // Antes del fix, este último paso lanzaba SlotNoLongerAvailableException
    // y ninguna reserva llegaba a crearse — $sent[4] nunca existía.
    expect(Booking::count())->toBe(1);
    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('quedó confirmado');

    $booking = Booking::first();
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($targetDate);
    $localHour = (int) $booking->starts_at->setTimezone('Asia/Tokyo')->format('H');
    expect($localHour)->toBeGreaterThanOrEqual(9);
    expect($localHour)->toBeLessThan(17);
});

/**
 * Corrección DateFieldExtractor/ruta IA — obligatorio: fuerza
 * deliberadamente la ruta de IA (createFromFormat(), no la determinista de
 * día de semana que usan los 2 tests de arriba), con Organization en
 * timezone no-default y recurso de horario ACOTADO (nunca 24h, que
 * enmascararía tanto este bug como el de BookingScheduler). Antes de esta
 * corrección, "en 15 días" hacía que DateFieldExtractor devolviera la
 * fecha etiquetada con config('app.timezone') en vez de Asia/Tokyo — el
 * slot ofrecido terminaba siendo, en términos absolutos, una hora fuera
 * del horario real del recurso, y BookingScheduler (ya corregido) lo
 * rechazaba con SlotNoLongerAvailableException.
 */
test('creación real vía ReservaAgent forzando la ruta IA ("en 15 días"), Resource de horario acotado y timezone no-default: acepta el slot ofrecido', function () {
    $organization = tzE2eFixtureOrganizationBoundedHours('Asia/Tokyo', 'wamid-tz-bounded-ai-create', '09:00', '17:00');
    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];

    // "en 15 días" no matchea ningún patrón determinista de
    // tryDeterministicParse() (ni día de semana, ni numérico con
    // separador) — llega garantizado a la rama de IA.
    $targetDate = CarbonImmutable::now('Asia/Tokyo')->addDays(15)->toDateString();
    $agent = tzE2eReservaAgent($drafts, $sent, tzE2eQueuedAi([$targetDate, 'Ana']));

    $agent->handle(tzE2eFixtureMessage('hola'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('en 15 días'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('Ana'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('1'), $session, $organization); // primer slot ofrecido = 09:00 local
    $agent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    // Antes de esta corrección, este último paso lanzaba
    // SlotNoLongerAvailableException y $sent[4] nunca llegaba a existir.
    expect(Booking::count())->toBe(1);
    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('quedó confirmado');

    $booking = Booking::first();
    // El instante persistido, convertido a Asia/Tokyo, debe ser exactamente
    // 09:00 (el primer slot ofrecido) del día pedido — ni la fecha ni la
    // hora deben haberse corrido por una conversión de timezone incorrecta.
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($targetDate);
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->format('H:i'))->toBe('09:00');
});

/**
 * Mismo caso, pero para reprogramación — recurso con horario acotado.
 */
test('reprogramación real vía GestionReservaAgent con Resource de horario acotado y timezone no-default: acepta el nuevo slot', function () {
    $organization = tzE2eFixtureOrganizationBoundedHours('Asia/Tokyo', 'wamid-tz-bounded-reschedule', '09:00', '17:00');
    $resource = $organization->resources()->first();

    $original = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: CarbonImmutable::now('Asia/Tokyo')->addDays(12)->setTime(10, 0),
        resource: $resource,
    ));
    $booking = Booking::findOrFail($original->bookingId);

    $session = tzE2eFixtureSession($organization);
    $drafts = tzE2eFakeDraftRepository();
    $sent = [];
    // "el viernes" (vía determinista, ver comentario del test de creación
    // anterior — evita la vía de IA de DateFieldExtractor).
    $newDate = CarbonImmutable::now('Asia/Tokyo')->startOfDay()->next(5)->toDateString(); // 5 = viernes
    $agent = tzE2eGestionReservaAgent($drafts, $sent, tzE2eQueuedAi([]));

    $agent->handle(tzE2eFixtureMessage('quiero reprogramar mi turno'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('reprogramar'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('el viernes'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('1'), $session, $organization);
    $agent->handle(tzE2eFixtureMessage('sí'), $session, $organization);

    $booking = $booking->fresh();
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->toDateString())->toBe($newDate);
    $localHour = (int) $booking->starts_at->setTimezone('Asia/Tokyo')->format('H');
    expect($localHour)->toBeGreaterThanOrEqual(9);
    expect($localHour)->toBeLessThan(17);
});
