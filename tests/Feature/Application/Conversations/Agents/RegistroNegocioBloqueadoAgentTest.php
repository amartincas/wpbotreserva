<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\Agents\RegistroNegocioBloqueadoAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function bloqueadoAgentFakeReplier(array &$sent): ConversationReplier
{
    return new ConversationReplier(new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $message];
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
    });
}

function bloqueadoAgentFixtureSession(Organization $organization): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-bloqueado-agent-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization->id,
    ]);
}

function bloqueadoAgentFixtureMessage(): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-bloqueado-agent', '+573001234567', 'quiero registrar mi negocio', now()->toImmutable());
}

test('responde con un mensaje claro de que el negocio ya está registrado', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $session = bloqueadoAgentFixtureSession($organization);
    $session->update(['current_intent' => Intent::RegistroNegocioBloqueado->value]);
    $sent = [];
    $agent = new RegistroNegocioBloqueadoAgent(bloqueadoAgentFakeReplier($sent), new BotMessageRepository, new EloquentConversationSessionRepository);

    $agent->handle(bloqueadoAgentFixtureMessage(), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('ya está registrado');
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
    expect($session->refresh()->current_intent)->toBeNull();
});

test('responde por el Channel de la sesión, no por el Channel vinculado a la Organization', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001234567']);
    $businessChannel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-bloqueado-business',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $businessChannel->organizations()->attach($organization->id, ['is_primary' => true]);
    $session = bloqueadoAgentFixtureSession($organization);
    $sent = [];
    $agent = new RegistroNegocioBloqueadoAgent(bloqueadoAgentFakeReplier($sent), new BotMessageRepository, new EloquentConversationSessionRepository);

    $agent->handle(bloqueadoAgentFixtureMessage(), $session, $organization);

    expect($sent[0]['channel']->is($session->channel))->toBeTrue();
    expect($sent[0]['channel']->is($businessChannel))->toBeFalse();
});
