<?php

use App\Application\Conversations\Classification\ButtonIntentStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function buttonStrategyFixtureSession(?Intent $currentIntent): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-button-strategy-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'current_intent' => $currentIntent?->value,
    ]);
}

function buttonStrategyFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-button-strategy', '+573001234567', $text, now()->toImmutable());
}

test('reconoce los 3 ids del menú inicial, aunque haya un Intent distinto (fuera_de_alcance) todavía activo en la sesión', function () {
    $session = buttonStrategyFixtureSession(Intent::FueraDeAlcance);
    $strategy = new ButtonIntentStrategy;

    expect($strategy->attempt(buttonStrategyFixtureMessage('menu_registro_negocio'), $session))->toBe(Intent::RegistroNegocio);
    expect($strategy->attempt(buttonStrategyFixtureMessage('menu_reserva'), $session))->toBe(Intent::Reserva);
    expect($strategy->attempt(buttonStrategyFixtureMessage('menu_gestion_reserva'), $session))->toBe(Intent::GestionReserva);
});

test('funciona igual sin ningún Intent activo en la sesión', function () {
    $session = buttonStrategyFixtureSession(null);
    $strategy = new ButtonIntentStrategy;

    expect($strategy->attempt(buttonStrategyFixtureMessage('menu_reserva'), $session))->toBe(Intent::Reserva);
});

test('un texto que no es exactamente uno de los 3 ids devuelve null, incluso si se parece', function () {
    $session = buttonStrategyFixtureSession(null);
    $strategy = new ButtonIntentStrategy;

    expect($strategy->attempt(buttonStrategyFixtureMessage('quiero reservar'), $session))->toBeNull();
    expect($strategy->attempt(buttonStrategyFixtureMessage('MENU_RESERVA'), $session))->toBeNull();
    expect($strategy->attempt(buttonStrategyFixtureMessage('menu_reserva extra'), $session))->toBeNull();
});
