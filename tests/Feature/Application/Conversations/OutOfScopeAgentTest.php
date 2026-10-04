<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\Agents\OutOfScopeAgent;
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

function outOfScopeFakeReplier(array &$calls): ConversationReplier
{
    return new ConversationReplier(new class($calls) implements ChannelClientInterface
    {
        public function __construct(private array &$calls) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->calls[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $message];
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
        {
            $this->calls[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $bodyText, 'buttons' => $buttons];
        }
    });
}

function outOfScopeFixtureChannel(string $phoneNumberId, ChannelRole $role = ChannelRole::BUSINESS): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function buildOutOfScopeAgent(array &$calls): OutOfScopeAgent
{
    return new OutOfScopeAgent(outOfScopeFakeReplier($calls), new EloquentConversationSessionRepository);
}

test('BUSINESS con Organization: envía el menú de botones (registrar negocio/reservar/gestionar) por el Channel de la sesión', function () {
    $calls = [];
    $channel = outOfScopeFixtureChannel('wamid-oos');
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);
    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567', 'organization_id' => $org->id]);
    $message = new InboundMessage('wamid.msg-oos', 'wamid-oos', '+573001234567', 'asdkjhaskjd', now()->toImmutable());

    buildOutOfScopeAgent($calls)->handle($message, $session);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($channel))->toBeTrue();
    expect($calls[0]['toPhoneE164'])->toBe('+573001234567');
    expect($calls[0]['message'])->not->toBeEmpty();
    expect($calls[0]['buttons'])->toHaveCount(3);
    expect(array_column($calls[0]['buttons'], 'id'))->toBe(['menu_registro_negocio', 'menu_reserva', 'menu_gestion_reserva']);
});

test('CENTRAL sin Organization: responde igual, por el CENTRAL', function () {
    $calls = [];
    $central = outOfScopeFixtureChannel('wamid-oos-central', ChannelRole::CENTRAL);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573001234567']);
    $message = new InboundMessage('wamid.msg-oos-central', 'wamid-oos-central', '+573001234567', 'hola', now()->toImmutable());

    buildOutOfScopeAgent($calls)->handle($message, $session);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($central))->toBeTrue();
    expect(array_column($calls[0]['buttons'], 'id'))->toContain('menu_registro_negocio');
});

test('un solo turno: limpia current_intent para que el próximo mensaje se vuelva a clasificar', function () {
    $calls = [];
    $central = outOfScopeFixtureChannel('wamid-oos-intent', ChannelRole::CENTRAL);
    $session = ConversationSession::create([
        'channel_id' => $central->id,
        'customer_phone' => '+573001234567',
        'current_intent' => Intent::FueraDeAlcance->value,
    ]);
    $message = new InboundMessage('wamid.msg-oos-intent', 'wamid-oos-intent', '+573001234567', 'hola', now()->toImmutable());

    buildOutOfScopeAgent($calls)->handle($message, $session);

    expect($session->fresh()->current_intent)->toBeNull();
});

test('AgentSelector encuentra invoker para FueraDeAlcance aunque no haya Organization', function () {
    $calls = [];
    $selector = new AgentSelector(centralAgents: [], businessAgents: [Intent::FueraDeAlcance->value => buildOutOfScopeAgent($calls)]);

    expect($selector->selectFor(ChannelRole::BUSINESS, Intent::FueraDeAlcance, null))->toBeInstanceOf(OrganizationlessAgentInvoker::class);
});
