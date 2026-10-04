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

/**
 * B4: el vínculo del Channel es la única fuente de verdad para un BUSINESS
 * — ya no se reutiliza session->organization_id como atajo. Si el número
 * se desvincula de su negocio, una sesión memoizada no puede seguir
 * resolviendo a esa Organization. (2+ vínculos no se puede construir: lo
 * impide UNIQUE(channel_id); el fail-closed se prueba en el Router.)
 */
test('ya no reutiliza organization_id de la sesión: un Channel desvinculado es Unregistered aunque la sesión tenga Organization', function () {
    $channel = orgResolverFixtureChannel();
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $session = ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $org->id,
    ]);

    $resolution = (new SingleOrganizationResolver)->resolve($channel, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Unregistered);
    expect($resolution->organization)->toBeNull();
});
