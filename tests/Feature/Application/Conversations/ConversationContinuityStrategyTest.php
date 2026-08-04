<?php

use App\Application\Conversations\Classification\ConversationContinuityStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function continuityFixtureMessage(string $text = 'cualquier cosa'): InboundMessage
{
    return new InboundMessage(
        phoneNumberId: 'wamid-continuity',
        fromPhone: '+573001234567',
        text: $text,
        receivedAt: now()->toImmutable(),
    );
}

function continuityFixtureSession(?string $currentIntent = null): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-continuity-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'current_intent' => $currentIntent,
    ]);
}

test('devuelve el Intent activo de la sesión sin mirar el contenido del mensaje', function () {
    $session = continuityFixtureSession(Intent::RegistroNegocio->value);

    $intent = (new ConversationContinuityStrategy)->attempt(continuityFixtureMessage('30 minutos'), $session);

    expect($intent)->toBe(Intent::RegistroNegocio);
});

test('devuelve null si la sesión no tiene un Intent activo', function () {
    $session = continuityFixtureSession(null);

    $intent = (new ConversationContinuityStrategy)->attempt(continuityFixtureMessage(), $session);

    expect($intent)->toBeNull();
});
