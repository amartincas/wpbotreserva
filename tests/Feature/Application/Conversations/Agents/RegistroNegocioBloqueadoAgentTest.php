<?php

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\RegistroNegocioBloqueadoAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function bloqueadoAgentFakeSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
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
    $sent = [];
    $agent = new RegistroNegocioBloqueadoAgent(bloqueadoAgentFakeSender($sent), new BotMessageRepository);

    $agent->handle(bloqueadoAgentFixtureMessage(), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('ya está registrado');
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
});
