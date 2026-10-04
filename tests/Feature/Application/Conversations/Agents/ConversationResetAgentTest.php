<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Conversations\Agents\ConversationResetAgent;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\OrganizationlessAgentInvoker;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function resetAgentFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function resetAgentFakeReplier(array &$sent): ConversationReplier
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

function resetAgentFixtureChannel(ChannelRole $role = ChannelRole::BUSINESS): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => 'wamid-reset-agent-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function resetAgentFixtureMessage(): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-reset-agent', '+573001234567', 'salir', now()->toImmutable());
}

test('BUSINESS con Organization: limpia el draft, borra el Intent activo y avisa al cliente por el Channel de la sesión', function () {
    $channel = resetAgentFixtureChannel();
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);
    $session = ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization->id,
        'current_intent' => Intent::GestionReserva->value,
    ]);
    $drafts = resetAgentFakeDraftRepository();
    $drafts->put($session, ['_awaiting_booking_selection' => true, '_candidateBookingIds' => [1, 2, 3]]);
    $sent = [];
    $agent = new ConversationResetAgent($drafts, new EloquentConversationSessionRepository, resetAgentFakeReplier($sent));

    $agent->handle(resetAgentFixtureMessage(), $session);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('empecemos de nuevo');
    expect($sent[0]['channel']->is($channel))->toBeTrue();
});

test('"salir" sin Organization (owner nuevo a mitad del onboarding en el CENTRAL): limpia el estado y responde por el CENTRAL', function () {
    $central = resetAgentFixtureChannel(ChannelRole::CENTRAL);
    $session = ConversationSession::create([
        'channel_id' => $central->id,
        'customer_phone' => '+573001234567',
        'current_intent' => Intent::RegistroNegocio->value,
    ]);
    $drafts = resetAgentFakeDraftRepository();
    $drafts->put($session, ['organizationName' => 'Barbería Don Carlos']);
    $sent = [];
    $agent = new ConversationResetAgent($drafts, new EloquentConversationSessionRepository, resetAgentFakeReplier($sent));

    $agent->handle(resetAgentFixtureMessage(), $session);

    expect($session->organization_id)->toBeNull();
    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['toPhoneE164'])->toBe('+573001234567');
});

test('AgentSelector encuentra invoker para Reset aunque no haya Organization — antes el "salir" se rechazaba en silencio', function () {
    $sent = [];
    $agent = new ConversationResetAgent(resetAgentFakeDraftRepository(), new EloquentConversationSessionRepository, resetAgentFakeReplier($sent));
    $selector = new AgentSelector(centralAgents: [Intent::Reset->value => $agent], businessAgents: [Intent::Reset->value => $agent]);
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    // B4: Reset está en la allowlist de los dos roles.
    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::Reset, null))->toBeInstanceOf(OrganizationlessAgentInvoker::class);
    expect($selector->selectFor(ChannelRole::BUSINESS, Intent::Reset, $organization))->toBeInstanceOf(OrganizationlessAgentInvoker::class);
});
