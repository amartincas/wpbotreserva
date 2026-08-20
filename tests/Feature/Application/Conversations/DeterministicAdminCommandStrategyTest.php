<?php

use App\Application\Conversations\Classification\DeterministicAdminCommandStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function adminStrategyFixtureOrganization(?string $ownerPhone = '+573009999999'): Organization
{
    return Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => $ownerPhone]);
}

function adminStrategyFixtureSession(?Organization $organization): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-admin-strategy-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization?->id,
    ]);
}

function adminStrategyFixtureMessage(string $text, string $fromPhone): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-admin-strategy', $fromPhone, $text, now()->toImmutable());
}

test('reconoce "reservas hoy" del dueño y devuelve Intent::AdminCommand', function () {
    $organization = adminStrategyFixtureOrganization();
    $session = adminStrategyFixtureSession($organization);
    $strategy = new DeterministicAdminCommandStrategy;

    $intent = $strategy->attempt(adminStrategyFixtureMessage('reservas hoy', '+573009999999'), $session);

    expect($intent)->toBe(Intent::AdminCommand);
});

test('reconoce "cancelar N" y "confirmar N" del dueño, sin importar mayúsculas/espacios', function () {
    $organization = adminStrategyFixtureOrganization();
    $session = adminStrategyFixtureSession($organization);
    $strategy = new DeterministicAdminCommandStrategy;

    expect($strategy->attempt(adminStrategyFixtureMessage('  CANCELAR   42  ', '+573009999999'), $session))->toBe(Intent::AdminCommand);
    expect($strategy->attempt(adminStrategyFixtureMessage('confirmar 7', '+573009999999'), $session))->toBe(Intent::AdminCommand);
});

test('un mensaje que no matchea ningún comando devuelve null, incluso viniendo del dueño', function () {
    $organization = adminStrategyFixtureOrganization();
    $session = adminStrategyFixtureSession($organization);
    $strategy = new DeterministicAdminCommandStrategy;

    expect($strategy->attempt(adminStrategyFixtureMessage('cancelar mi turno de mañana', '+573009999999'), $session))->toBeNull();
    expect($strategy->attempt(adminStrategyFixtureMessage('hola', '+573009999999'), $session))->toBeNull();
});

test('un cliente que no es el dueño nunca reclama el intent, aunque el texto matchee exacto', function () {
    $organization = adminStrategyFixtureOrganization();
    $session = adminStrategyFixtureSession($organization);
    $strategy = new DeterministicAdminCommandStrategy;

    $intent = $strategy->attempt(adminStrategyFixtureMessage('cancelar 5', '+573001112233'), $session);

    expect($intent)->toBeNull();
});

test('sin organización resuelta en la sesión, nunca reclama el intent', function () {
    $session = adminStrategyFixtureSession(null);
    $strategy = new DeterministicAdminCommandStrategy;

    $intent = $strategy->attempt(adminStrategyFixtureMessage('reservas hoy', '+573009999999'), $session);

    expect($intent)->toBeNull();
});

test('si la organización no tiene owner_phone configurado, nunca reclama el intent', function () {
    $organization = adminStrategyFixtureOrganization(ownerPhone: null);
    $session = adminStrategyFixtureSession($organization);
    $strategy = new DeterministicAdminCommandStrategy;

    $intent = $strategy->attempt(adminStrategyFixtureMessage('reservas hoy', '+573009999999'), $session);

    expect($intent)->toBeNull();
});
