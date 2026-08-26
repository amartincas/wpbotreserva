<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Conversations\Agents\RegistroNegocioAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

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
        $ai,
    );
}

test('el primer mensaje del flujo pregunta el nombre del negocio, sin llamar a la IA', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('hola quiero registrar mi negocio'), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('nombre de tu negocio');
    expect($drafts->get($session)['_started'])->toBeTrue();
});

test('un nombre de negocio de una sola palabra pide confirmación con botones antes de avanzar a ciudad', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroQueuedAi(['Impulzar']));

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('Impulzar'), $session);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('Impulzar');
    expect(array_column($sent[1]['buttons'], 'id'))->toBe(['si', 'no']);
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

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('ciudad');
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

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('nombre de tu negocio');
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

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('ciudad');
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

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('Impulzar');
    expect(array_column($sent[1]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($drafts->get($session)['_awaitingNameConfirmation'])->toBeTrue();
    expect($drafts->get($session)['_pendingOrganizationName'])->toBe('Impulzar');
});

test('Incremento 4: recolecta varios servicios y varios recursos uno a la vez (bucle con "¿agregás otro?") hasta llegar al resumen de confirmación', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $ai = registroQueuedAi([
        'Restaurante El Sabor',
        'Bogotá',
        'Calle 15 #20-10',
        'Corte de cabello',
        '30',
        'Barba',
        '20',
        'Carlos',
        json_encode([
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]),
        'Ana',
        json_encode([
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '18:00'],
            ['weekday' => 3, 'start_time' => '10:00', 'end_time' => '18:00'],
        ]),
    ]);
    $agent = buildRegistroAgent($drafts, $sent, $ai);

    $agent->handle(registroFixtureMessage('hola'), $session); // dispara el flujo
    $agent->handle(registroFixtureMessage('Restaurante El Sabor'), $session);
    $agent->handle(registroFixtureMessage('Bogotá'), $session);
    $agent->handle(registroFixtureMessage('Calle 15 #20-10'), $session); // completa los 3 fijos -> arranca servicios
    $agent->handle(registroFixtureMessage('Corte de cabello'), $session);
    $agent->handle(registroFixtureMessage('30 minutos'), $session);
    $agent->handle(registroFixtureMessage('sí'), $session); // agrega otro servicio
    $agent->handle(registroFixtureMessage('Barba'), $session);
    $agent->handle(registroFixtureMessage('20 minutos'), $session);
    $agent->handle(registroFixtureMessage('no'), $session); // termina servicios -> arranca recursos
    $agent->handle(registroFixtureMessage('Carlos'), $session);
    $agent->handle(registroFixtureMessage('Lunes de 9 a 17'), $session);
    $agent->handle(registroFixtureMessage('sí'), $session); // agrega otro recurso
    $agent->handle(registroFixtureMessage('Ana'), $session);
    $agent->handle(registroFixtureMessage('Martes y miércoles de 10 a 18'), $session);
    $agent->handle(registroFixtureMessage('no'), $session); // termina recursos -> confirmación

    expect($sent)->toHaveCount(16);
    $summary = $sent[15]['message'];
    expect($summary)->toContain('Restaurante El Sabor');
    expect($summary)->toContain('Corte de cabello');
    expect($summary)->toContain('Barba');
    expect($summary)->toContain('Carlos');
    expect($summary)->toContain('Ana');
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect($drafts->get($session)['services'])->toHaveCount(2);
    expect($drafts->get($session)['resources'])->toHaveCount(2);

    expect(Organization::count())->toBe(0); // todavía no se confirmó

    $agent->handle(registroFixtureMessage('sí'), $session);

    $org = Organization::firstOrFail();
    expect($org->name)->toBe('Restaurante El Sabor');
    expect($org->services)->toHaveCount(2);
    expect($org->resources)->toHaveCount(2);
    $carlos = $org->resources->firstWhere('display_name', 'Carlos');
    $ana = $org->resources->firstWhere('display_name', 'Ana');
    expect($carlos->schedules)->toHaveCount(1);
    expect($ana->schedules)->toHaveCount(2);
    // Cruce completo: cada servicio queda habilitado para los 2 recursos.
    foreach ($org->services as $service) {
        expect($service->resources)->toHaveCount(2);
    }
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
            ['name' => 'Corte de cabello', 'durationMinutes' => 30],
        ],
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new App\Application\Tenancy\WeeklyScheduleSlot(1, '09:00', '17:00')]],
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
            ['name' => 'Corte de cabello', 'durationMinutes' => 30],
        ],
        'resources' => [
            ['name' => 'Carlos', 'weeklySchedule' => [new App\Application\Tenancy\WeeklyScheduleSlot(1, '09:00', '17:00')]],
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
            ['name' => 'Carlos', 'weeklySchedule' => [new App\Application\Tenancy\WeeklyScheduleSlot(1, '09:00', '17:00')]],
        ],
    ]);

    $sent = [];
    $agent = buildRegistroAgent($drafts, $sent, registroNeverCalledAi());

    $agent->handle(registroFixtureMessage('tal vez'), $session);

    expect(Organization::count())->toBe(0);
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect($sent)->toHaveCount(1);
});
