<?php

use App\Application\Organizations\OrganizationResolutionStatus;
use App\Application\Organizations\SingleOrganizationResolver;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function orgResolverFixtureChannel(string $phoneNumberId = 'wamid-org-resolver'): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

test('resuelve directo cuando el Channel tiene exactamente una organización vinculada', function () {
    $channel = orgResolverFixtureChannel();
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);

    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    $resolution = (new SingleOrganizationResolver)->resolve($channel, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Resolved);
    expect($resolution->organization->is($org))->toBeTrue();
});

test('devuelve Unregistered cuando el Channel no tiene ninguna organización vinculada', function () {
    $channel = orgResolverFixtureChannel();
    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    $resolution = (new SingleOrganizationResolver)->resolve($channel, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Unregistered);
    expect($resolution->organization)->toBeNull();
});

test('devuelve PendingDisambiguation con los candidatos cuando el Channel tiene varias organizaciones', function () {
    $channel = orgResolverFixtureChannel();
    $orgA = Organization::create(['name' => 'Barbería A']);
    $orgB = Organization::create(['name' => 'Barbería B']);
    $channel->organizations()->attach([$orgA->id => ['is_primary' => true], $orgB->id => ['is_primary' => false]]);

    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    $resolution = (new SingleOrganizationResolver)->resolve($channel, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::PendingDisambiguation);
    expect($resolution->candidates)->toHaveCount(2);
});

test('reutiliza organization_id ya resuelto en la sesión sin volver a consultar el pivot', function () {
    $channel = orgResolverFixtureChannel();
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    // A propósito NO se vincula el channel a la organización — si el
    // resolver consultara el pivot, devolvería NotFound. Que devuelva
    // Resolved prueba que usó el shortcut de session->organization_id.
    $session = ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $org->id,
    ]);

    $resolution = (new SingleOrganizationResolver)->resolve($channel, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Resolved);
    expect($resolution->organization->is($org))->toBeTrue();
});
