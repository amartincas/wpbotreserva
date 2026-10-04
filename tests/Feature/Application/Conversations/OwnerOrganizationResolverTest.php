<?php

use App\Application\Organizations\OrganizationResolutionStatus;
use App\Application\Organizations\OwnerOrganizationResolver;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;

function ownerResolverFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-owner-resolver-central',
        'status' => ChannelStatus::ACTIVE,
    ]);
}

test('owner con Organization: la resuelve por owner_phone', function () {
    $central = ownerResolverFixtureCentral();
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573009999999']);

    $resolution = (new OwnerOrganizationResolver)->resolve($central, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Resolved);
    expect($resolution->organization->is($org))->toBeTrue();
});

test('owner sin Organization (teléfono desconocido): Unregistered, habilita el onboarding', function () {
    $central = ownerResolverFixtureCentral();
    Organization::create(['name' => 'Otro Negocio', 'owner_phone' => '+573008888888']);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573009999999']);

    $resolution = (new OwnerOrganizationResolver)->resolve($central, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Unregistered);
    expect($resolution->organization)->toBeNull();
});

test('nunca resuelve desde channel_organization: un vínculo del Channel (insertado por SQL) no le da Organization a quien no es su owner', function () {
    $central = ownerResolverFixtureCentral();
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573008888888']);
    // Saltea a propósito la invariante del pivot: simula el estado corrupto.
    DB::table('channel_organization')->insert([
        'channel_id' => $central->id, 'organization_id' => $org->id, 'is_primary' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573009999999']);

    $resolution = (new OwnerOrganizationResolver)->resolve($central, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Unregistered);
});

test('nunca usa session->organization_id memoizado si el teléfono ya no es el owner', function () {
    $central = ownerResolverFixtureCentral();
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573008888888']);
    $session = ConversationSession::create([
        'channel_id' => $central->id,
        'customer_phone' => '+573009999999',
        'organization_id' => $org->id,
    ]);

    $resolution = (new OwnerOrganizationResolver)->resolve($central, $session);

    expect($resolution->status)->toBe(OrganizationResolutionStatus::Unregistered);
});
