<?php

use App\Application\Conversations\Classification\ResetKeywordStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function resetStrategyFixtureSession(?Intent $currentIntent): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-reset-strategy-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'current_intent' => $currentIntent?->value,
    ]);
}

function resetStrategyFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-reset-strategy', '+573001234567', $text, now()->toImmutable());
}

test('con un flujo activo, reconoce "salir" y devuelve Intent::Reset', function () {
    $session = resetStrategyFixtureSession(Intent::GestionReserva);
    $strategy = new ResetKeywordStrategy;

    $intent = $strategy->attempt(resetStrategyFixtureMessage('salir'), $session);

    expect($intent)->toBe(Intent::Reset);
});

test('normaliza mayúsculas y espacios', function () {
    $session = resetStrategyFixtureSession(Intent::Reserva);
    $strategy = new ResetKeywordStrategy;

    $intent = $strategy->attempt(resetStrategyFixtureMessage('  SALIR  '), $session);

    expect($intent)->toBe(Intent::Reset);
});

test('sin flujo activo (current_intent null), nunca reclama el intent aunque el texto matchee', function () {
    $session = resetStrategyFixtureSession(null);
    $strategy = new ResetKeywordStrategy;

    $intent = $strategy->attempt(resetStrategyFixtureMessage('salir'), $session);

    expect($intent)->toBeNull();
});

test('"cancelar" solo nunca dispara el reset — ya significa algo real dentro de GestionReservaAgent', function () {
    $session = resetStrategyFixtureSession(Intent::GestionReserva);
    $strategy = new ResetKeywordStrategy;

    $intent = $strategy->attempt(resetStrategyFixtureMessage('cancelar'), $session);

    expect($intent)->toBeNull();
});

test('un mensaje que no matchea ninguna palabra de reset devuelve null', function () {
    $session = resetStrategyFixtureSession(Intent::Reserva);
    $strategy = new ResetKeywordStrategy;

    expect($strategy->attempt(resetStrategyFixtureMessage('mañana'), $session))->toBeNull();
    expect($strategy->attempt(resetStrategyFixtureMessage('quiero salir a caminar'), $session))->toBeNull();
});
