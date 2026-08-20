<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\ConversationResetAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
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

function resetAgentFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'message');
        }
    };
}

test('limpia el draft, borra el Intent activo y avisa al cliente', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-reset-agent-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);
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
    $sessions = new EloquentConversationSessionRepository;
    $agent = new ConversationResetAgent($drafts, $sessions, resetAgentFakeNotificationSender($sent));

    $message = new InboundMessage('wamid.msg-'.uniqid(), 'wamid-reset-agent', '+573001234567', 'salir', now()->toImmutable());
    $agent->handle($message, $session, $organization);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('empecemos de nuevo');
});
