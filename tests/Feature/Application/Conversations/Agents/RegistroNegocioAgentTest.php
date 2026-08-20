<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Conversations\Agents\RegistroNegocioAgent;
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

test('el primer mensaje del flujo pregunta el nombre del negocio, sin llamar a la IA', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        registroNeverCalledAi(),
    );

    $agent->handle(registroFixtureMessage('hola quiero registrar mi negocio'), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('nombre de tu negocio');
    expect($drafts->get($session)['_started'])->toBeTrue();
});

test('si todos los steps ya están respondidos pero todavía no se pidió confirmación, pasa a confirmación en vez de romper', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $drafts->put($session, [
        '_started' => true,
        'organizationName' => 'Restaurante El Sabor',
        'city' => 'Bogotá',
        'address' => 'Calle 15 #20-10',
        'serviceName' => 'Corte de cabello',
        'serviceDurationMinutes' => '30',
        'resourceName' => 'Carlos',
        'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')],
    ]);
    $sent = [];
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        registroNeverCalledAi(),
    );

    $agent->handle(registroFixtureMessage('cualquier cosa'), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('Restaurante El Sabor');
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
});

test('progresa un campo a la vez hasta llegar al resumen de confirmación', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $ai = registroQueuedAi([
        'Restaurante El Sabor',
        'Bogotá',
        'Calle 15 #20-10',
        'Corte de cabello',
        '30',
        'Carlos',
        json_encode([
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['weekday' => 4, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['weekday' => 5, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]),
    ]);
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        $ai,
    );

    $agent->handle(registroFixtureMessage('hola'), $session); // dispara el flujo
    $agent->handle(registroFixtureMessage('Restaurante El Sabor'), $session);
    $agent->handle(registroFixtureMessage('Bogotá'), $session);
    $agent->handle(registroFixtureMessage('Calle 15 #20-10'), $session);
    $agent->handle(registroFixtureMessage('Corte de cabello'), $session);
    $agent->handle(registroFixtureMessage('30 minutos'), $session);
    $agent->handle(registroFixtureMessage('Carlos'), $session);
    $agent->handle(registroFixtureMessage('Lunes a Viernes de 9 a 17'), $session);

    expect($sent)->toHaveCount(8);
    $summary = $sent[7]['message'];
    expect($summary)->toContain('Restaurante El Sabor');
    expect($summary)->toContain('Bogotá');
    expect($summary)->toContain('Carlos');
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();

    expect(Organization::count())->toBe(0); // todavía no se confirmó
});

test('re-pregunta con el motivo cuando el extractor no puede interpretar la respuesta, sin avanzar el draft', function () {
    $session = registroFixtureSession();
    $drafts = registroFakeDraftRepository();
    $sent = [];
    $ai = registroQueuedAi(['NO_ENCONTRADO']);
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        $ai,
    );

    $agent->handle(registroFixtureMessage('hola'), $session);
    $agent->handle(registroFixtureMessage('asdkjhasd'), $session);

    expect($sent)->toHaveCount(2);
    expect($sent[1]['message'])->not->toBe($sent[0]['message']);
    expect($drafts->get($session))->not->toHaveKey('organizationName');
});

test('al confirmar con sí, registra la organización, limpia el draft y el Intent, y confirma al cliente', function () {
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
        'serviceName' => 'Corte de cabello',
        'serviceDurationMinutes' => '30',
        'resourceName' => 'Carlos',
        'weeklySchedule' => [
            new WeeklyScheduleSlot(1, '09:00', '17:00'),
        ],
    ];
    $drafts->put($session, $completeDraft);

    $sent = [];
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        $sessions,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        registroNeverCalledAi(),
    );

    $agent->handle(registroFixtureMessage('sí'), $session);

    expect(Organization::count())->toBe(1);
    $org = Organization::first();
    expect($org->name)->toBe('Restaurante El Sabor');
    expect($org->owner_phone)->toBe('+573001234567');
    expect($org->channels->first()->id)->toBe($session->channel_id);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('Restaurante El Sabor');
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
        'serviceName' => 'Corte de cabello',
        'serviceDurationMinutes' => '30',
        'resourceName' => 'Carlos',
        'weeklySchedule' => [new WeeklyScheduleSlot(1, '09:00', '17:00')],
    ]);

    $sent = [];
    $agent = new RegistroNegocioAgent(
        new ConversationalFlowRunner,
        $drafts,
        new EloquentConversationSessionRepository,
        registroFakeChannelClient($sent),
        new RegisterOrganizationCommand(new UnlimitedEntitlementChecker),
        registroNeverCalledAi(),
    );

    $agent->handle(registroFixtureMessage('tal vez'), $session);

    expect(Organization::count())->toBe(0);
    expect($drafts->get($session)['_awaiting_confirmation'])->toBeTrue();
    expect($sent)->toHaveCount(1);
});
