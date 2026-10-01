<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Conversations\Agents\RegistroNegocioExpiradoAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function expiradoAgentFakeChannelClient(array &$sent): ChannelClientInterface
{
    return new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = compact('to', 'message');
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
    };
}

function expiradoAgentFakeDraftRepository(array $initial = []): ConversationDraftRepositoryInterface
{
    return new class($initial) implements ConversationDraftRepositoryInterface
    {
        public function __construct(private array $store) {}

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

function expiradoAgentFixtureSession(): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-expirado-agent-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $sessions = new EloquentConversationSessionRepository;
    $session = $sessions->findOrCreateFor($channel, '+573001234567');
    $sessions->recordIntent($session, Intent::RegistroNegocio);

    return $session;
}

function expiradoAgentFixtureMessage(): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-expirado-agent', '+573001234567', 'hola', now()->toImmutable());
}

test('avisa explícitamente que el registro anterior expiró', function () {
    $session = expiradoAgentFixtureSession();
    $sent = [];
    $drafts = expiradoAgentFakeDraftRepository(['stale' => 'data']);
    $agent = new RegistroNegocioExpiradoAgent(
        expiradoAgentFakeChannelClient($sent),
        $drafts,
        new EloquentConversationSessionRepository,
        new BotMessageRepository,
    );

    $agent->handle(expiradoAgentFixtureMessage(), $session);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('expiró');
});

test('limpia el draft y el current_intent, dejando la sesión lista para un registro genuinamente nuevo', function () {
    $session = expiradoAgentFixtureSession();
    expect($session->current_intent)->toBe(Intent::RegistroNegocio->value);

    $sent = [];
    $drafts = expiradoAgentFakeDraftRepository([$session->id => ['organizationName' => 'Nombre a medio escribir']]);
    $agent = new RegistroNegocioExpiradoAgent(
        expiradoAgentFakeChannelClient($sent),
        $drafts,
        new EloquentConversationSessionRepository,
        new BotMessageRepository,
    );

    $agent->handle(expiradoAgentFixtureMessage(), $session);

    expect($drafts->get($session))->toBe([]); // vacío porque forget() corrió, no porque nunca se sembró
    expect($session->fresh()->current_intent)->toBeNull();
});
