<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\GestionNegocioAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Tenancy\AddResourceCommand;
use App\Application\Tenancy\AddServiceCommand;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ReplaceResourceScheduleCommand;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function gestionNegocioFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function gestionNegocioFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['organization' => $organization, 'toPhoneE164' => $toPhoneE164, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

/**
 * @param  string[]  $responses
 */
function gestionNegocioQueuedAi(array $responses): AiServiceInterface
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

function gestionNegocioNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

function gestionNegocioFixtureOrganization(int $resourceCount = 1): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-gestion-negocio-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $resources = [];
    foreach (range(1, $resourceCount) as $i) {
        $resources[] = new ResourceRegistrationData("Recurso {$i}", [
            new WeeklyScheduleSlot(1, '09:00', '17:00'),
        ]);
    }

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: $resources,
    ));

    return Organization::findOrFail($result->organizationId);
}

function gestionNegocioFixtureSession(Organization $organization): ConversationSession
{
    return ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => '+573009999999',
        'organization_id' => $organization->id,
    ]);
}

function gestionNegocioFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-gestion-negocio', '+573009999999', $text, now()->toImmutable());
}

function buildGestionNegocioAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): GestionNegocioAgent
{
    return new GestionNegocioAgent(
        $drafts,
        new EloquentConversationSessionRepository,
        gestionNegocioFakeNotificationSender($sent),
        new AddServiceCommand(app(EntitlementCheckerInterface::class)),
        new AddResourceCommand(app(EntitlementCheckerInterface::class)),
        new ReplaceResourceScheduleCommand,
        new BotMessageRepository,
        $ai,
    );
}

test('un disparador genérico ("administrar mi negocio") pregunta con botones qué quiere hacer, sin llamar a la IA', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('administrar mi negocio'), $session, $organization);

    expect($sent)->toHaveCount(2);
    expect($sent[0]['message'])->toBe('¡Hola! Soy el asistente de WpbotReserva.');
    expect(array_column($sent[1]['buttons'], 'id'))->toBe(['agregar_servicio', 'cambiar_horario']);
    expect($drafts->get($session)['_awaitingAction'])->toBeTrue();
});

test('caso real: si el disparador ya es "agregar servicio", salta directo a pedir el nombre — no vuelve a preguntar qué quiere hacer', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('Agregar Servicio'), $session, $organization);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('nombre del servicio');
    expect($sent[1])->not->toHaveKey('buttons');
    expect($drafts->get($session)['_awaitingServiceName'])->toBeTrue();
    expect($drafts->get($session))->not->toHaveKey('_awaitingAction');
});

test('caso real: si el disparador ya es "cambiar horario", salta directo al flujo de horario — no vuelve a preguntar qué quiere hacer', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 1);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('cambiar horario'), $session, $organization);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('Recurso 1');
    expect($drafts->get($session)['_awaitingNewSchedule'])->toBeTrue();
    expect($drafts->get($session))->not->toHaveKey('_awaitingAction');
});

test('una elección que no es ninguno de los 2 botones vuelve a preguntar', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('no sé'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('No entendí');
    expect($drafts->get($session)['_awaitingAction'])->toBeTrue();
});

test('caso real (segunda ronda): con un solo recurso en el negocio, igual pregunta quién lo presta — como al crear el primer servicio', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 1);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('Recurso 1');
    expect($sent[3]['message'])->toContain('Agregar una persona nueva');
    expect($drafts->get($session)['_awaitingServiceResourceSelection'])->toBeTrue();

    $agent->handle(gestionNegocioFixtureMessage('1'), $session, $organization); // elige "Recurso 1"
    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization); // no agrega otro

    expect(array_column($sent[array_key_last($sent)]['buttons'], 'id'))->toBe(['si', 'no']);

    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization);

    $service = $organization->fresh()->services()->where('name', 'Barba')->firstOrFail();
    expect($service->resources)->toHaveCount(1);
    expect($service->resources->first()->display_name)->toBe('Recurso 1');
});

test('caso real (segunda ronda): elegir "0" da de alta una persona nueva con su propio horario, tal como al registrar el negocio', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 1);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $newScheduleJson = json_encode([
        ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '18:00'],
    ]);
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Masaje moldeador', '45', 'Edgar Torres', $newScheduleJson]));

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Masaje moldeador'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('45 minutos'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('0'), $session, $organization); // "Agregar una persona nueva"

    expect($drafts->get($session)['_awaitingNewResourceName'])->toBeTrue();

    $agent->handle(gestionNegocioFixtureMessage('Edgar Torres'), $session, $organization);

    expect($drafts->get($session)['_awaitingNewResourceSchedule'])->toBeTrue();
    expect($sent[array_key_last($sent)]['message'])->toContain('Edgar Torres');

    $agent->handle(gestionNegocioFixtureMessage('martes de 10 a 18'), $session, $organization);

    // La persona ya quedó creada como recurso real del negocio, con su horario.
    $resource = Resource::where('display_name', 'Edgar Torres')->firstOrFail();
    expect($resource->organization_id)->toBe($organization->id);
    expect($resource->schedules)->toHaveCount(1);
    expect($resource->schedules->first()->weekday)->toBe(2);
    expect($drafts->get($session)['_awaitingAddAnotherServiceResource'])->toBeTrue();

    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization);

    $service = $organization->fresh()->services()->where('name', 'Masaje moldeador')->firstOrFail();
    expect($service->resources)->toHaveCount(1);
    expect($service->resources->first()->display_name)->toBe('Edgar Torres');
});

test('caso real: Agregar servicio con varios recursos en el negocio pregunta quién lo presta — NO lo habilita para todos por default', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 2);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('agregar_servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);

    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('Barba');
    expect($sent[4]['message'])->toContain('Recurso 1');
    expect($sent[4]['message'])->toContain('Recurso 2');
    expect($drafts->get($session)['_awaitingServiceResourceSelection'])->toBeTrue();

    $agent->handle(gestionNegocioFixtureMessage('1'), $session, $organization); // elige "Recurso 1"

    expect($sent)->toHaveCount(6);
    expect($sent[5]['message'])->toContain('otra persona o recurso');
    expect($drafts->get($session)['_awaitingAddAnotherServiceResource'])->toBeTrue();

    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization); // no agrega otro

    expect($sent)->toHaveCount(7);
    expect($sent[6]['message'])->toContain('Recurso 1');
    expect($sent[6]['message'])->not->toContain('Recurso 2');
    expect(array_column($sent[6]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($organization->fresh()->services()->count())->toBe(1); // todavía no se confirmó

    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization);

    $service = $organization->fresh()->services()->where('name', 'Barba')->firstOrFail();
    expect($service->duration_minutes)->toBe(20);
    // Solo el recurso elegido — NUNCA todos por default.
    expect($service->resources)->toHaveCount(1);
    expect($service->resources->first()->display_name)->toBe('Recurso 1');
    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
});

test('Agregar servicio: se puede elegir más de un recurso repitiendo "sí" en "¿agregás otro?"', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 3);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('1'), $session, $organization); // Recurso 1
    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization); // agrega otro
    $agent->handle(gestionNegocioFixtureMessage('3'), $session, $organization); // Recurso 3
    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization); // no agrega más
    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization); // confirma

    $service = $organization->fresh()->services()->where('name', 'Barba')->firstOrFail();
    expect($service->resources)->toHaveCount(2);
    expect($service->resources->pluck('display_name')->sort()->values()->all())->toBe(['Recurso 1', 'Recurso 3']);
});

test('Agregar servicio: si no confirma, no crea nada', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('1'), $session, $organization); // elige "Recurso 1"
    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization); // no agrega otro recurso
    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization); // no confirma

    expect($organization->fresh()->services()->count())->toBe(1);
    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
});

test('Cambiar horario: con un solo recurso, no pregunta cuál — pide directo el horario nuevo', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 1);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('cambiar_horario'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('Recurso 1');
    expect($drafts->get($session)['_awaitingNewSchedule'])->toBeTrue();
    expect($drafts->get($session))->not->toHaveKey('_awaitingResourceSelection');
});

test('Cambiar horario: con varios recursos, pregunta cuál (numerado)', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 2);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('cambiar_horario'), $session, $organization);

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('Recurso 1');
    expect($sent[2]['message'])->toContain('Recurso 2');
    expect($drafts->get($session)['_awaitingResourceSelection'])->toBeTrue();
});

test('Cambiar horario: reemplaza el horario completo del recurso elegido al confirmar', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 2);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $newScheduleJson = json_encode([
        ['weekday' => 5, 'start_time' => '14:00', 'end_time' => '20:00'],
    ]);
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi([$newScheduleJson]));

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('cambiar_horario'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('2'), $session, $organization); // elige "Recurso 2"
    $agent->handle(gestionNegocioFixtureMessage('viernes de 2 a 8pm'), $session, $organization);

    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('Recurso 2');
    expect(array_column($sent[4]['buttons'], 'id'))->toBe(['si', 'no']);

    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization);

    $resource2 = Resource::where('display_name', 'Recurso 2')->firstOrFail();
    expect($resource2->schedules)->toHaveCount(1);
    expect($resource2->schedules->first()->weekday)->toBe(5);

    $resource1 = Resource::where('display_name', 'Recurso 1')->firstOrFail();
    expect($resource1->schedules)->toHaveCount(1); // el otro recurso no se tocó
    expect($resource1->schedules->first()->weekday)->toBe(1);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
});

test('Cambiar horario: una selección de recurso inválida re-pregunta sin avanzar', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 2);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('cambiar_horario'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('no sé cuál'), $session, $organization);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('No entendí la opción');
    expect($drafts->get($session)['_awaitingResourceSelection'])->toBeTrue();
});

test('Fase 3: el saludo se manda en burbuja aparte antes de la primera pregunta, una sola vez por conversación', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('no sé'), $session, $organization);

    // El saludo es el primer mensaje, en su propia burbuja (no concatenado
    // a "¿Cuál es el nombre del servicio nuevo?").
    expect($sent[0]['message'])->toBe('¡Hola! Soy el asistente de WpbotReserva.');
    expect($sent[0]['message'])->not->toContain('nombre del servicio');

    // No se repite en el segundo mensaje de la misma conversación.
    expect(collect($sent)->pluck('message')->filter(fn ($m) => $m === '¡Hola! Soy el asistente de WpbotReserva.'))->toHaveCount(1);
});
