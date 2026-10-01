<?php

use App\Application\Conversations\Classification\ExpiredRegistroNegocioStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function expiredRegistroFixtureMessage(string $text = 'cualquier cosa'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-expired-registro', '+573001234567', $text, now()->toImmutable());
}

/**
 * $minutesAgo controla updated_at vía CarbonImmutable::setTestNow() en el
 * momento de crear la sesión (mismo patrón ya usado en el resto del
 * proyecto para tests de timezone/TTL) — no un update() posterior, que
 * Eloquent sobrescribiría con el timestamp real al guardar.
 */
function expiredRegistroFixtureSession(?string $currentIntent, int $minutesAgo = 0): ConversationSession
{
    CarbonImmutable::setTestNow(now()->subMinutes($minutesAgo));

    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-expired-registro-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $session = ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'current_intent' => $currentIntent,
    ]);

    CarbonImmutable::setTestNow();

    return $session;
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('RegistroNegocio dentro del TTL de continuidad: no tiene opinión', function () {
    $session = expiredRegistroFixtureSession(Intent::RegistroNegocio->value, minutesAgo: 30);

    $intent = (new ExpiredRegistroNegocioStrategy)->attempt(expiredRegistroFixtureMessage(), $session);

    expect($intent)->toBeNull();
});

test('RegistroNegocio vencido (más del TTL): produce RegistroNegocioExpirado', function () {
    // TTL por defecto = 240 (config/conversations.php) — 300 lo supera.
    $session = expiredRegistroFixtureSession(Intent::RegistroNegocio->value, minutesAgo: 300);

    $intent = (new ExpiredRegistroNegocioStrategy)->attempt(expiredRegistroFixtureMessage(), $session);

    expect($intent)->toBe(Intent::RegistroNegocioExpirado);
});

test('sesión que nunca inició ningún flujo (current_intent null): no se confunde con uno vencido', function () {
    $session = expiredRegistroFixtureSession(null, minutesAgo: 300);

    $intent = (new ExpiredRegistroNegocioStrategy)->attempt(expiredRegistroFixtureMessage(), $session);

    expect($intent)->toBeNull();
});

test('otro Intent vencido (no RegistroNegocio): no le corresponde a esta Strategy', function () {
    $session = expiredRegistroFixtureSession(Intent::Reserva->value, minutesAgo: 300);

    $intent = (new ExpiredRegistroNegocioStrategy)->attempt(expiredRegistroFixtureMessage(), $session);

    expect($intent)->toBeNull();
});
