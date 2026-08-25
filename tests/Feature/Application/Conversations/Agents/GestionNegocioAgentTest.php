<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\GestionNegocioAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
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
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
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
        new ReplaceResourceScheduleCommand,
        $ai,
    );
}

test('el primer mensaje pregunta con botones qué quiere hacer el dueño, sin llamar a la IA', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('agregar servicio'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect(array_column($sent[0]['buttons'], 'id'))->toBe(['agregar_servicio', 'cambiar_horario']);
    expect($drafts->get($session)['_awaitingAction'])->toBeTrue();
});

test('una elección que no es ninguno de los 2 botones vuelve a preguntar', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioNeverCalledAi());

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('no sé'), $session, $organization);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('No entendí');
    expect($drafts->get($session)['_awaitingAction'])->toBeTrue();
});

test('Agregar servicio: pide nombre, duración, confirma con botones, y al confirmar lo crea habilitado para todos los recursos', function () {
    $organization = gestionNegocioFixtureOrganization(resourceCount: 2);
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('agregar_servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('Barba');
    expect(array_column($sent[3]['buttons'], 'id'))->toBe(['si', 'no']);
    expect($organization->fresh()->services()->count())->toBe(1); // todavía no se confirmó

    $agent->handle(gestionNegocioFixtureMessage('sí'), $session, $organization);

    expect($sent)->toHaveCount(5);
    expect($sent[4]['message'])->toContain('Barba');
    $service = $organization->fresh()->services()->where('name', 'Barba')->firstOrFail();
    expect($service->duration_minutes)->toBe(20);
    expect($service->resources)->toHaveCount(2);
    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
});

test('Agregar servicio: si no confirma, no crea nada', function () {
    $organization = gestionNegocioFixtureOrganization();
    $session = gestionNegocioFixtureSession($organization);
    $drafts = gestionNegocioFakeDraftRepository();
    $sent = [];
    $agent = buildGestionNegocioAgent($drafts, $sent, gestionNegocioQueuedAi(['Barba', '20']));

    $agent->handle(gestionNegocioFixtureMessage('hola'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('agregar_servicio'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('Barba'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('20 minutos'), $session, $organization);
    $agent->handle(gestionNegocioFixtureMessage('no'), $session, $organization);

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

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('Recurso 1');
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

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->toContain('Recurso 1');
    expect($sent[1]['message'])->toContain('Recurso 2');
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

    expect($sent)->toHaveCount(4);
    expect($sent[3]['message'])->toContain('Recurso 2');
    expect(array_column($sent[3]['buttons'], 'id'))->toBe(['si', 'no']);

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

    expect($sent)->toHaveCount(3);
    expect($sent[2]['message'])->toContain('No entendí la opción');
    expect($drafts->get($session)['_awaitingResourceSelection'])->toBeTrue();
});
