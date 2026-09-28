<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Conversations\Agents\RegistroNegocioAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\BotMessage;

function registroFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function registroFakeChannelClient(array &$sent): ChannelClientInterface
{
    return new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = compact('channel', 'to', 'message');
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['channel' => $channel, 'to' => $to, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

/**
 * @param  string[]  $responses  Devueltas en orden, una por cada llamada a getResponse().
 */
function registroQueuedAi(array $responses): AiServiceInterface
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

function registroNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

function registroFixtureSession(string $phoneNumberId = 'wamid-registro'): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
}

function registroFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-registro', '+573001234567', $text, now()->toImmutable());
}

function buildRegistroAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): RegistroNegocioAgent
{
    return new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        new BotMessageRepository,
        $ai,
    );
}

test('el primer mensaje del flujo pregunta el nombre del negocio, sin llamar a la IA', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('hola quiero registrar mi negocio'), $session);

    expect($sent)->toHaveCount(2);
    expect($sent[0]['message'])->toBe('¡Hola! Soy el asistente de WpbotReserva.');
    expect($sent[1]['message'])->toContain('nombre de tu negocio');
    expect($drafts->get($session)['_started'])->toBeTrue();
});

test('un nombre de negocio de una sola palabra pide confirmación con botones antes de avanzar a ciudad', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Impulzar']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Impulzar'), $session);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('Impulzar');
    expect(array_column($sent[2]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($drafts->get($session)['_awaitingNameConfirmation'])->toBeTrue();
    expect($drafts->get($session)['_pendingOrganizationName'])->toBe('Impulzar');
    expect($drafts->get($session))->not->toHaveKey('organizationName');
});

test('confirmar el nombre corto con sí lo guarda y avanza a preguntar la ciudad', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Impulzar']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Impulzar'), $session);
    $agent->handle(registroFixtureMessage('sí'), $session);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('ciudad');
    expect($drafts->get($session)['organizationName'])->toBe('Impulzar');
    expect($drafts->get($session))->not->toHaveKey('_awaitingNameConfirmation');
    expect($drafts->get($session))->not->toHaveKey('_pendingOrganizationName');
});

test('rechazar el nombre corto con no vuelve a preguntar el nombre, sin guardarlo', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Impulzar']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Impulzar'), $session);
    $agent->handle(registroFixtureMessage('no'), $session);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('nombre de tu negocio');
    expect($drafts->get($session))->not->toHaveKey('organizationName');
    expect($drafts->get($session))->not->toHaveKey('_awaitingNameConfirmation');
});

test('un nombre de negocio de varias palabras no pide confirmación', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Restaurante El Sabor']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Restaurante El Sabor'), $session);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('ciudad');
    expect($drafts->get($session)['organizationName'])->toBe('Restaurante El Sabor');
});

test('si los 3 campos fijos ya están respondidos pero todavía no arrancó la fase de servicios, pasa a esa fase en vez de romper', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $drafts->put($session, [
        '_started' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'organizationDescription' => null,
    ]);
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('cualquier cosa'), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('servicio');
    expect($drafts->get($session)['_collectingServices'])->toBeTrue();
    expect($drafts->get($session)['services'])->toBe([]);
});

test('re-pregunta con el motivo cuando el extractor no puede interpretar la respuesta en un campo que no es el nombre, sin avanzar el draft', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    // Arranca ya con el nombre puesto para que el campo actual sea "city",
    // no "organizationName" (ese campo tiene su propio comportamiento,
    // probado aparte abajo).
    $drafts->put($session, ['_started' => true, 'organizationName' => 'Restaurante El Sabor']);
    $sent = [];
    $ai = registroQueuedAi(['NO_ENCONTRADO']);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('asdkjhasd'), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('ciudad');
    expect($drafts->get($session))->not->toHaveKey('city');
});

test('caso real (segunda ronda): si la IA rechaza de plano el nombre del negocio (NO_ENCONTRADO), usa la respuesta cruda como candidato y confirma en vez de repetir "no entendí"', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['NO_ENCONTRADO']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Impulzar'), $session);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('Impulzar');
    expect(array_column($sent[2]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($drafts->get($session)['_awaitingNameConfirmation'])->toBeTrue();
    expect($drafts->get($session)['_pendingOrganizationName'])->toBe('Impulzar');
});

test('post-E2E Fase 1 (Hallazgo 2, caso B): con 2+ recursos existentes, el 2do servicio sigue ofreciendo selección explícita — nada de cruce cartesiano', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();

    // Post-E2E Fase 1 (Hallazgo 1, segunda ronda): con la autoasignación
    // también aplicada a la transición 0→1 (no solo a begin() con 1 ya
    // existente), ya NO hay forma de llegar a 2+ recursos a través de la
    // conversación normal de RegistroNegocioAgent — el primer servicio
    // nunca vuelve a preguntar "¿agregás otra persona?" una vez creado el
    // único recurso. Se siembra el draft directamente (mismo patrón que
    // "si los 3 campos fijos ya están respondidos..." en este archivo) para
    // seguir verificando que ServiceResourceSelectionFlow, cuando SÍ hay
    // 2+ recursos en el catálogo (sin importar cómo llegaron ahí — ver
    // riesgo documentado en el informe), sigue ofreciendo selección
    // explícita en vez de autoasignar.
    $drafts->put($session, [
        '_started' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'organizationDescription' => null,
        '_collectingServices' => true,
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')]],
            ['name' => 'Ana', 'weeklySchedule' => [new WeeklyScheduleSlot(2, '10:00', '18:00'), new WeeklyScheduleSlot(3, '10:00', '18:00')]],
        ],
        'services' => [
            ['name' => 'Corte de cabello', 'durationMinutes' => 30, 'description' => null, 'price' => null, 'resourceKeys' => [0, 1]],
        ],
        '_awaitingAddAnotherService' => true,
    ]);
    $sent = [];
    $ai = registroQueuedAi(['Barba']);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('sí'), $session); // agrega otro servicio
    $agent->handle(registroFixtureMessage('Barba'), $session);
    $agent->handle(registroFixtureMessage('20 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del servicio
    $agent->handle(registroFixtureMessage('no'), $session); // sin precio -> 2 recursos existentes (Carlos, Ana) -> caso B: menú explícito, NO autoasignación

    $barbaMenuIndex = array_key_last($sent);
    expect($sent[$barbaMenuIndex]['message'])->toContain('Quién va a prestar el servicio *Barba*');
    expect($sent[$barbaMenuIndex]['message'])->toContain('1) Carlos');
    expect($sent[$barbaMenuIndex]['message'])->toContain('2) Ana');

    $agent->handle(registroFixtureMessage('2'), $session); // Barba la presta solo Ana, no Carlos

    // handleServiceResourceSelection() (selección desde un menú real) no lo
    // toca ni Hallazgo 1 ni Hallazgo 2 — sigue preguntando "¿agregás otra
    // persona?" tras una elección explícita.
    expect($sent[array_key_last($sent)]['message'])->toContain('¿Agregás otra persona');

    $agent->handle(registroFixtureMessage('no'), $session); // termina recursos de Barba -> ¿agregás otro servicio?
    $agent->handle(registroFixtureMessage('no'), $session); // termina servicios -> confirmación

    $summary = $sent[array_key_last($sent)]['message'];
    expect($summary)->toContain('Restaurante El Sabor');
    expect($summary)->toContain('Corte de cabello');
    expect($summary)->toContain('Barba');
    expect($summary)->toContain('Carlos');
    expect($summary)->toContain('Ana');

    // Post-E2E Fase 1 (Hallazgo 3): nombres de día en español, nunca
    // índices numéricos crudos.
    expect($summary)->toContain('Lunes de 09:00 a 17:00');
    expect($summary)->toContain('Martes de 10:00 a 18:00');
    expect($summary)->toContain('Miércoles de 10:00 a 18:00');
    expect($summary)->not->toContain('día 1');
    expect($summary)->not->toContain('día 2');
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect($drafts->get($session)['services'])->toHaveCount(2);
    expect($drafts->get($session)['resources'])->toHaveCount(2);

    // Corte de cabello: Carlos Y Ana (ambos elegidos explícitamente). Barba:
    // solo Ana — nada de cruce cartesiano implícito.
    expect($drafts->get($session)['services'][0]['resourceKeys'])->toBe([0, 1]); // Corte de cabello -> Carlos, Ana
    expect($drafts->get($session)['services'][1]['resourceKeys'])->toBe([1]); // Barba -> Ana

    expect(Organization::count())->toBe(0); // todavía no se confirmó

    $agent->handle(registroFixtureMessage('sí'), $session);

    $org = Organization::firstOrFail();
    expect($org->name)->toBe('Restaurante El Sabor');
    expect($org->services)->toHaveCount(2);
    expect($org->resources)->toHaveCount(2);
    $carlos = $org->resources->firstWhere('display_name', 'Carlos');
    $ana = $org->resources->firstWhere('display_name', 'Ana');

    $corte = $org->services->firstWhere('name', 'Corte de cabello');
    $barba = $org->services->firstWhere('name', 'Barba');

    expect($corte->resources->pluck('id')->sort()->values()->all())
        ->toBe(collect([$carlos->id, $ana->id])->sort()->values()->all());
    expect($barba->resources->pluck('id')->all())->toBe([$ana->id]);
});

test('post-E2E Fase 1 (Hallazgo 2, caso A): un recurso ya cargado en un servicio anterior se autoasigna al siguiente sin preguntar (un recurso puede prestar varios servicios)', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    // '30'/'45' ya no van en la cola: la duración numérica desnuda ahora es
    // determinista (DurationFieldExtractor), no consume la IA.
    $ai = registroQueuedAi([
        'Restaurante El Sabor',
        'Bogotá',
        'Calle 15 #20-10',
        'Corte de cabello',
        'Carlos',
        'Corte + Barba',
    ]);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Restaurante El Sabor'), $session);
    $agent->handle(registroFixtureMessage('Bogotá'), $session);
    $agent->handle(registroFixtureMessage('Calle 15 #20-10'), $session);
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del negocio
    $agent->handle(registroFixtureMessage('Corte de cabello'), $session);
    $agent->handle(registroFixtureMessage('30 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del servicio
    $agent->handle(registroFixtureMessage('no'), $session); // sin precio
    $agent->handle(registroFixtureMessage('Carlos'), $session);
    // Post-E2E Fase 1 (Hallazgo 1, segunda ronda): tras crear a Carlos (el
    // único recurso), el draft queda con exactamente 1 -> autoasigna y pasa
    // directo a "¿agregás otro servicio?", sin preguntar "¿agregás otra
    // persona?" -> ya no hace falta responder esa pregunta acá.
    $agent->handle(registroFixtureMessage('Lunes de 9 a 17'), $session);
    $agent->handle(registroFixtureMessage('sí'), $session);
    $agent->handle(registroFixtureMessage('Corte + Barba'), $session);
    $agent->handle(registroFixtureMessage('45 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del servicio
    $agent->handle(registroFixtureMessage('no'), $session); // sin precio -> Carlos es el único recurso -> se autoasigna sin preguntar (Hallazgo 2, caso A)

    // Post-E2E Fase 1 (Hallazgo 1, segunda ronda): "va a prestar el
    // servicio" y "¿Agregás otra persona?" NUNCA aparecen — ni para el 1er
    // servicio (0→1, autoasignación en la creación) ni para el 2do (1
    // existente, autoasignación en begin()).
    expect(collect($sent)->pluck('message')->filter(fn ($m) => str_contains($m, 'va a prestar el servicio')))->toHaveCount(0);
    expect(collect($sent)->pluck('message')->filter(fn ($m) => str_contains($m, '¿Agregás otra persona')))->toHaveCount(0);

    $agent->handle(registroFixtureMessage('no'), $session); // termina servicios -> confirmación
    $agent->handle(registroFixtureMessage('sí'), $session);

    $org = Organization::firstOrFail();
    expect($org->services)->toHaveCount(2);
    expect($org->resources)->toHaveCount(1); // nunca se creó un segundo recurso

    $carlos = $org->resources->firstOrFail();
    expect($carlos->display_name)->toBe('Carlos');

    // Carlos queda prestando los dos servicios.
    foreach ($org->services as $service) {
        expect($service->resources->pluck('id')->all())->toBe([$carlos->id]);
    }
});

test('post-E2E Fase 1 (Hallazgo 1, segunda ronda): negocio nuevo, primer servicio — tras crear el único recurso pasa directo a "¿agregás otro servicio?"', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $ai = registroQueuedAi([
        'Spa Lucía',
        'Cali',
        'Carrera 10 #20-30',
        'Masaje relajante',
        'Laura',
    ]);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Spa Lucía'), $session);
    $agent->handle(registroFixtureMessage('Cali'), $session);
    $agent->handle(registroFixtureMessage('Carrera 10 #20-30'), $session);
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del negocio
    $agent->handle(registroFixtureMessage('Masaje relajante'), $session);
    $agent->handle(registroFixtureMessage('45 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del servicio
    $agent->handle(registroFixtureMessage('no'), $session); // sin precio -> 0 recursos -> pide directo el nombre
    $agent->handle(registroFixtureMessage('Laura'), $session);
    $agent->handle(registroFixtureMessage('Lunes de 9 a 17'), $session); // Laura queda como el único recurso -> autoasignación inmediata

    // La pregunta "¿Agregás otra persona o recurso para este servicio?"
    // NUNCA aparece — se pasa directo a "¿agregás otro servicio?".
    expect($sent)->not->toBeEmpty();
    $lastMessage = $sent[array_key_last($sent)]['message'];
    expect($lastMessage)->not->toContain('¿Agregás otra persona');
    expect($lastMessage)->toBe('¿Agregás otro servicio?');
    expect($drafts->get($session))->not->toHaveKey('_awaitingAddAnotherServiceResource');
    expect($drafts->get($session)['_awaitingAddAnotherService'])->toBeTrue();
});

test('una respuesta que no es ni sí ni no en "¿agregás otro servicio?" vuelve a preguntar, sin avanzar de fase', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $drafts->put($session, [
        '_started' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        '_collectingServices' => true,
        'services' => [['name' => 'Corte de cabello', 'durationMinutes' => 30]],
        '_awaitingAddAnotherService' => true,
    ]);
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('no sé'), $session);

    expect($sent)->toHaveCount(1);
    expect(array_column($sent[0]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($drafts->get($session)['_awaitingAddAnotherService'])->toBeTrue();
    expect($drafts->get($session)['services'])->toHaveCount(1);
});

test('al confirmar con sí, registra la organización con sus servicios/recursos, limpia el draft y el Intent, y confirma al cliente', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sessions = new EloquentConversationSessionRepository;
    $sessions->recordIntent($session, Intent::RegistroNegocio);

    $completeDraft = [
        '_started' => true,
        '_awaiting_confirmation' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'services' => [
            ['name' => 'Corte de cabello', 'durationMinutes' => 30, 'description' => null, 'price' => null, 'resourceKeys' => [0]],
        ],
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')]],
        ],
    ];
    $drafts->put($session, $completeDraft);

    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('sí'), $session);

    expect(Organization::count())->toBe(1);
    $org = Organization::first();
    expect($org->name)->toBe('Restaurante El Sabor');
    expect($org->owner_phone)->toBe('+573001234567');
    expect($org->channels->first()->id)->toBe($session->channel_id);
    expect($org->services)->toHaveCount(1);
    expect($org->resources)->toHaveCount(1);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
    expect($session->fresh()->organization_id)->toBe($org->id);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('Restaurante El Sabor');
});

test('caso real: si la sesión ya estaba memoizada a otra organización (número de prueba compartido), el registro la reengancha a la recién creada', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sessions = new EloquentConversationSessionRepository;
    $sessions->recordIntent($session, Intent::RegistroNegocio);

    // Simula el bug real: esta sesión había quedado pinneada a OTRA
    // organización de una prueba anterior con el mismo número compartido.
    $staleOrganization = Organization::create(['name' => 'AMC Studios', 'owner_phone' => '+573128340860']);
    $sessions->attachOrganization($session, $staleOrganization);
    expect($session->fresh()->organization_id)->toBe($staleOrganization->id);

    $drafts->put($session, [
        '_started' => true,
        '_awaiting_confirmation' => true,
        'organizationName' => 'Impulzar',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'services' => [
            ['name' => 'Corte de cabello', 'durationMinutes' => 30, 'description' => null, 'price' => null, 'resourceKeys' => [0]],
        ],
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')]],
        ],
    ]);

    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('sí'), $session);

    $newOrganization = Organization::where('name', 'Impulzar')->firstOrFail();
    // Ya no apunta a la organización vieja — apunta a la que este mismo
    // teléfono acaba de crear como dueño.
    expect($session->fresh()->organization_id)->toBe($newOrganization->id);
});

test('si la respuesta de confirmación no es un sí, vuelve a pedir confirmación sin ejecutar el Command', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();

    $drafts->put($session, [
        '_started' => true,
        '_awaiting_confirmation' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'services' => [
            ['name' => 'Corte de cabello', 'durationMinutes' => 30],
        ],
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')]],
        ],
    ]);

    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('tal vez'), $session);

    expect(Organization::count())->toBe(0);
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect($sent)->toHaveCount(1);
});

test('Fase 2: editar el mensaje "registro.nombre_negocio" en bot_messages cambia lo que el Agent efectivamente envía', function () {
    BotMessage::where('key', 'registro.nombre_negocio')
        ->firstOrFail()
        ->update(['template' => '¿Cómo se llama tu negocio?']);

    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('hola'), $session);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toBe('¿Cómo se llama tu negocio?');
    expect($sent[1]['message'])->not->toContain('¿Cuál es el nombre de tu negocio?');
});

test('Fase 3: el saludo se manda en burbuja aparte antes de la primera pregunta, una sola vez por conversación', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Restaurante El Sabor']));

    $agent->handle(registroFixtureMessage('hola quiero registrar mi negocio'), $session);
    $agent->handle(registroFixtureMessage('Restaurante El Sabor'), $session);

    // El saludo es el primer mensaje, en su propia burbuja (no concatenado
    // a "¿Cuál es el nombre de tu negocio?").
    expect($sent[0]['message'])->toBe('¡Hola! Soy el asistente de WpbotReserva.');
    expect($sent[0]['message'])->not->toContain('nombre de tu negocio');

    // No se repite en mensajes siguientes de la misma conversación.
    expect(collect($sent)->pluck('message')->filter(fn ($m) => $m === '¡Hola! Soy el asistente de WpbotReserva.'))->toHaveCount(1);
});

test('post-E2E Fase 1 (Hallazgo 1): 2 servicios en la misma conversación, cada uno con una respuesta de precio distinta, sin contaminación entre sí', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    // '45'/'30' ya no van en la cola: la duración numérica desnuda ahora es
    // determinista (DurationFieldExtractor), no consume la IA.
    $ai = registroQueuedAi([
        'Spa Bienestar',
        'Cali',
        'Carrera 10 #20-30',
        'Masaje',
        'Laura',
        'Consulta',
    ]);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Spa Bienestar'), $session);
    $agent->handle(registroFixtureMessage('Cali'), $session);
    $agent->handle(registroFixtureMessage('Carrera 10 #20-30'), $session);
    $agent->handle(registroFixtureMessage('no'), $session); // sin descripción del negocio
    $agent->handle(registroFixtureMessage('Masaje'), $session);
    $agent->handle(registroFixtureMessage('45 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('Relajante'), $session); // descripción del servicio 1
    $agent->handle(registroFixtureMessage('45000'), $session); // precio NUMÉRICO del servicio 1
    $agent->handle(registroFixtureMessage('Laura'), $session); // único recurso -> 0 existentes, pide nombre
    // Post-E2E Fase 1 (Hallazgo 1, segunda ronda): tras crear a Laura (el
    // único recurso), autoasigna y pasa directo a "¿agregás otro
    // servicio?" — ya no pregunta "¿agregás otra persona?", así que no
    // hace falta responder "no" a esa pregunta acá.
    $agent->handle(registroFixtureMessage('Lunes de 9 a 17'), $session);
    $agent->handle(registroFixtureMessage('sí'), $session);
    $agent->handle(registroFixtureMessage('Consulta'), $session);
    $agent->handle(registroFixtureMessage('30 minutos'), $session); // determinista, no consume la cola de IA
    $agent->handle(registroFixtureMessage('depende de la valoración'), $session); // descripción del servicio 2 (también hace de condición de precio)
    $agent->handle(registroFixtureMessage('no'), $session); // precio del servicio 2: rechazo explícito, NUNCA numérico -> Laura es la única existente, se autoasigna
    $agent->handle(registroFixtureMessage('no'), $session); // termina servicios -> confirmación
    $agent->handle(registroFixtureMessage('sí'), $session);

    $org = Organization::firstOrFail();
    $masaje = $org->services->firstWhere('name', 'Masaje');
    $consulta = $org->services->firstWhere('name', 'Consulta');

    // Servicio 1: precio numérico real, descripción SIN contaminar con nada del servicio 2.
    expect((float) $masaje->price)->toBe(45000.0);
    expect($masaje->description)->toBe('Relajante');

    // Servicio 2: precio NULL (nunca inventado, nunca heredado del
    // servicio 1), descripción intacta — el "no" del precio no dejó
    // ningún texto de condición anexado porque fue un rechazo, no una
    // condición.
    expect($consulta->price)->toBeNull();
    expect($consulta->description)->toBe('depende de la valoración');

    // Ambos comparten el único recurso (autoasignado), pero eso no afecta
    // ni mezcla sus precios/descripciones individuales.
    expect($masaje->resources->pluck('display_name')->all())->toBe(['Laura']);
    expect($consulta->resources->pluck('display_name')->all())->toBe(['Laura']);
});
