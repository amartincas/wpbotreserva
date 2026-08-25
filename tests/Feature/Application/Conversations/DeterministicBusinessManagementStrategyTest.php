<?php

use App\Application\Conversations\Classification\DeterministicBusinessManagementStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function businessMgmtStrategyFixtureOrganization(?string $ownerPhone = '+573009999999'): Organization
{
    return Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => $ownerPhone]);
}

function businessMgmtStrategyFixtureSession(?Organization $organization): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-business-mgmt-strategy-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization?->id,
    ]);
}

function businessMgmtStrategyFixtureMessage(string $text, string $fromPhone): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-business-mgmt-strategy', $fromPhone, $text, now()->toImmutable());
}

test('reconoce las frases de agregar servicio y cambiar horario del dueño, y devuelve Intent::GestionNegocio', function () {
    $organization = businessMgmtStrategyFixtureOrganization();
    $session = businessMgmtStrategyFixtureSession($organization);
    $strategy = new DeterministicBusinessManagementStrategy;

    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('agregar servicio', '+573009999999'), $session))->toBe(Intent::GestionNegocio);
    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('  CAMBIAR HORARIO  ', '+573009999999'), $session))->toBe(Intent::GestionNegocio);
    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('nuevo servicio', '+573009999999'), $session))->toBe(Intent::GestionNegocio);
});

test('un mensaje que no matchea ninguna frase devuelve null, incluso viniendo del dueño', function () {
    $organization = businessMgmtStrategyFixtureOrganization();
    $session = businessMgmtStrategyFixtureSession($organization);
    $strategy = new DeterministicBusinessManagementStrategy;

    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('hola', '+573009999999'), $session))->toBeNull();
    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('quiero agregar un servicio de barbería', '+573009999999'), $session))->toBeNull();
});

test('un cliente que no es el dueño nunca reclama el intent, aunque el texto matchee exacto', function () {
    $organization = businessMgmtStrategyFixtureOrganization();
    $session = businessMgmtStrategyFixtureSession($organization);
    $strategy = new DeterministicBusinessManagementStrategy;

    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('agregar servicio', '+573001112233'), $session))->toBeNull();
});

test('sin organización resuelta en la sesión, nunca reclama el intent', function () {
    $session = businessMgmtStrategyFixtureSession(null);
    $strategy = new DeterministicBusinessManagementStrategy;

    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('agregar servicio', '+573009999999'), $session))->toBeNull();
});

test('si la organización no tiene owner_phone configurado, nunca reclama el intent', function () {
    $organization = businessMgmtStrategyFixtureOrganization(ownerPhone: null);
    $session = businessMgmtStrategyFixtureSession($organization);
    $strategy = new DeterministicBusinessManagementStrategy;

    expect($strategy->attempt(businessMgmtStrategyFixtureMessage('agregar servicio', '+573009999999'), $session))->toBeNull();
});
