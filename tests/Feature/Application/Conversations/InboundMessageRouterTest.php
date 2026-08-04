<?php

use App\Application\Channels\PhoneNumberIdChannelResolver;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\InboundMessageRouter;
use App\Application\Organizations\SingleOrganizationResolver;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Events\InboundMessageRejected;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\Event;

function routerFixtureMessage(string $phoneNumberId, string $text = 'hola'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), $phoneNumberId, '+573001234567', $text, now()->toImmutable());
}

/**
 * Classifier falso: nunca llama IA real, deja al test decidir el Intent —
 * cada test de Router prueba orquestación, no clasificación (ya cubierta en
 * CompositeIntentClassifierTest/AiIntentClassifierStrategyTest).
 */
function routerFixtureClassifier(Intent $intent): IntentClassifierInterface
{
    return new class($intent) implements IntentClassifierInterface
    {
        public function __construct(private readonly Intent $intent) {}

        public function classify(InboundMessage $message, ConversationSession $session): Intent
        {
            return $this->intent;
        }
    };
}

function routerFixtureAgent(array &$calls): AgentInterface
{
    return new class($calls) implements AgentInterface
    {
        public function __construct(private array &$calls) {}

        public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
        {
            $this->calls[] = compact('message', 'session', 'organization');
        }
    };
}

function routerFixtureOrganizationlessAgent(array &$calls): OrganizationlessAgentInterface
{
    return new class($calls) implements OrganizationlessAgentInterface
    {
        public function __construct(private array &$calls) {}

        public function handle(InboundMessage $message, ConversationSession $session): void
        {
            $this->calls[] = compact('message', 'session');
        }
    };
}

function buildRouter(Intent $intent, array &$agentCalls, ?array $agentsByIntent = null): InboundMessageRouter
{
    $agentsByIntent ??= [$intent->value => routerFixtureAgent($agentCalls)];

    return new InboundMessageRouter(
        new PhoneNumberIdChannelResolver,
        new EloquentConversationSessionRepository,
        new SingleOrganizationResolver,
        routerFixtureClassifier($intent),
        new AgentSelector($agentsByIntent),
    );
}

test('rechaza el mensaje si el Channel no existe', function () {
    Event::fake([InboundMessageRejected::class]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('no-existe'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_not_found');
    expect($calls)->toBeEmpty();
});

test('rechaza el mensaje si el Channel existe pero no está activo', function () {
    Event::fake([InboundMessageRejected::class]);
    Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-inactive',
        'status' => ChannelStatus::SUSPENDED,
    ]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-inactive'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_inactive');
    expect($calls)->toBeEmpty();
});

test('Channel sin organización vinculada (Unregistered) rechaza un Intent que requiere Organization', function () {
    Event::fake([InboundMessageRejected::class]);
    Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-noorg',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $calls = [];
    // Intent::Reserva mapea a un Agent normal (AgentInterface) que exige
    // Organization — sin ella, AgentSelector no devuelve invoker.
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-noorg'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'agent_not_available');
    expect($calls)->toBeEmpty();
});

test('Channel sin organización vinculada (Unregistered) sí delega en un OrganizationlessAgentInterface, con organización null', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-registro',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $calls = [];
    $agent = routerFixtureOrganizationlessAgent($calls);
    $router = buildRouter(Intent::RegistroNegocio, $calls, agentsByIntent: [Intent::RegistroNegocio->value => $agent]);

    $message = routerFixtureMessage('wamid-router-registro', 'quiero registrar mi negocio');
    $router->handle($message);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['message'])->toBe($message);
    expect($calls[0]['session']->channel_id)->toBe($channel->id);

    $session = ConversationSession::where('channel_id', $channel->id)->firstOrFail();
    expect($session->organization_id)->toBeNull();
    expect($session->current_intent)->toBe('registro_negocio');
});

test('rechaza el mensaje si el Channel tiene varias organizaciones y ninguna está resuelta en la sesión', function () {
    Event::fake([InboundMessageRejected::class]);
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-ambiguous',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $channel->organizations()->attach([
        Organization::create(['name' => 'A'])->id => ['is_primary' => true],
        Organization::create(['name' => 'B'])->id => ['is_primary' => false],
    ]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-ambiguous'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'organization_pending_disambiguation');
    expect($calls)->toBeEmpty();
});

test('rechaza el mensaje si no hay Agent registrado para el Intent clasificado, pero ya persiste organización e intent', function () {
    Event::fake([InboundMessageRejected::class]);
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-noagent',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);
    $calls = [];
    $router = buildRouter(Intent::RegistroNegocio, $calls, agentsByIntent: []);

    $router->handle(routerFixtureMessage('wamid-router-noagent'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'agent_not_available');
    expect($calls)->toBeEmpty();

    $session = ConversationSession::where('channel_id', $channel->id)->firstOrFail();
    expect($session->organization_id)->toBe($org->id);
    expect($session->current_intent)->toBe('registro_negocio');
});

test('camino feliz: resuelve todo y delega en el Agent correcto con la organización resuelta', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-happy',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $message = routerFixtureMessage('wamid-router-happy', 'quiero un turno');
    $router->handle($message);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['message'])->toBe($message);
    expect($calls[0]['organization']->is($org))->toBeTrue();
    expect($calls[0]['session']->channel_id)->toBe($channel->id);

    $session = ConversationSession::where('channel_id', $channel->id)->firstOrFail();
    expect($session->organization_id)->toBe($org->id);
    expect($session->current_intent)->toBe('reserva');
});

test('en el segundo mensaje de la misma conversación, reutiliza la organización ya resuelta sin necesitar el pivot', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-router-continuity',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-continuity', 'primer mensaje'));

    // Se desvincula el channel de la organización a propósito: si el
    // segundo mensaje volviera a resolver por el pivot, ahora daría
    // Unregistered. Que siga funcionando prueba el shortcut de continuidad.
    $channel->organizations()->detach($org->id);

    $router->handle(routerFixtureMessage('wamid-router-continuity', 'segundo mensaje'));

    expect($calls)->toHaveCount(2);
    expect($calls[1]['organization']->is($org))->toBeTrue();
});
