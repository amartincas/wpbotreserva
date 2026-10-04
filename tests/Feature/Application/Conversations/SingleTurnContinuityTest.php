<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\Agents\OutOfScopeAgent;
use App\Application\Conversations\Agents\RegistroNegocioBloqueadoAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\Classification\ConversationContinuityStrategy;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

/**
 * B3 — hallazgo H1 de la auditoría: los agentes de un solo turno dejaban
 * current_intent activo, y ConversationContinuityStrategy (que corre antes
 * que la IA, con TTL de horas) devolvía ese mismo Intent para cada mensaje
 * siguiente. Caso real que bloqueaba: un owner nuevo recibe el menú de
 * FueraDeAlcance en el CENTRAL y después escribe "quiero registrar mi
 * negocio" — seguía cayendo en FueraDeAlcance en vez de clasificarse.
 */
function singleTurnFakeReplier(): ConversationReplier
{
    return new ConversationReplier(new class implements ChannelClientInterface
    {
        public function sendTextMessage(Channel $channel, string $to, string $message): void {}

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
    });
}

function singleTurnFixtureSession(Intent $intent, ?Organization $organization = null): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-continuity-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization?->id,
        'current_intent' => $intent->value,
    ]);
}

function singleTurnMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-continuity', '+573001234567', $text, now()->toImmutable());
}

test('sin la limpieza, continuidad atrapaba el mensaje siguiente (línea base del bug)', function () {
    $session = singleTurnFixtureSession(Intent::FueraDeAlcance);

    expect((new ConversationContinuityStrategy)->attempt(singleTurnMessage('quiero registrar mi negocio'), $session))
        ->toBe(Intent::FueraDeAlcance);
});

test('después de FueraDeAlcance en el CENTRAL (sin Organization), el mensaje siguiente ya no queda atrapado', function () {
    $session = singleTurnFixtureSession(Intent::FueraDeAlcance);

    (new OutOfScopeAgent(singleTurnFakeReplier(), new EloquentConversationSessionRepository))
        ->handle(singleTurnMessage('hola'), $session);

    expect((new ConversationContinuityStrategy)->attempt(singleTurnMessage('quiero registrar mi negocio'), $session->fresh()))
        ->toBeNull();
});

test('después de RegistroNegocioBloqueado, el mensaje siguiente ya no queda atrapado', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $session = singleTurnFixtureSession(Intent::RegistroNegocioBloqueado, $organization);

    (new RegistroNegocioBloqueadoAgent(singleTurnFakeReplier(), new BotMessageRepository, new EloquentConversationSessionRepository))
        ->handle(singleTurnMessage('quiero registrar mi negocio'), $session, $organization);

    expect((new ConversationContinuityStrategy)->attempt(singleTurnMessage('agregar servicio'), $session->fresh()))
        ->toBeNull();
});
