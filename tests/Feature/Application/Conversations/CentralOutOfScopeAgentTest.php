<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\Agents\CentralOutOfScopeAgent;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function centralOosFakeReplier(array &$calls): ConversationReplier
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

function centralOosFixtureSession(): ConversationSession
{
    $central = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-central-oos',
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $central->id,
        'customer_phone' => '+573001234567',
        'current_intent' => Intent::FueraDeAlcance->value,
    ]);
}

test('menú exclusivamente administrativo: registrar negocio, gestionar negocio y consultar agenda — nunca opciones de cliente', function () {
    $calls = [];
    $session = centralOosFixtureSession();
    $message = new InboundMessage('wamid.msg-central-oos', 'wamid-central-oos', '+573001234567', 'hola', now()->toImmutable());

    (new CentralOutOfScopeAgent(centralOosFakeReplier($calls), new EloquentConversationSessionRepository))->handle($message, $session);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($session->channel))->toBeTrue();
    expect(array_column($calls[0]['buttons'], 'title'))->toBe(['Registrar negocio', 'Gestionar negocio', 'Consultar agenda']);

    $ids = array_column($calls[0]['buttons'], 'id');
    expect($ids)->not->toContain('menu_reserva');
    expect($ids)->not->toContain('menu_gestion_reserva');
    expect($calls[0]['message'])->toContain('WhatsApp del negocio');
});

test('cada botón vuelve como un texto que ya reconoce una strategy existente', function () {
    expect(array_column(CentralOutOfScopeAgent::BUTTONS, 'id'))
        ->toBe(['menu_registro_negocio', 'gestionar negocio', 'que citas tengo hoy']);
});

test('un solo turno: limpia current_intent al terminar', function () {
    $calls = [];
    $session = centralOosFixtureSession();
    $message = new InboundMessage('wamid.msg-central-oos-2', 'wamid-central-oos', '+573001234567', 'hola', now()->toImmutable());

    (new CentralOutOfScopeAgent(centralOosFakeReplier($calls), new EloquentConversationSessionRepository))->handle($message, $session);

    expect($session->fresh()->current_intent)->toBeNull();
});
