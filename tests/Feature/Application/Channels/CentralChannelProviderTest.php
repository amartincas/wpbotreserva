<?php

use App\Application\Channels\CentralChannelProvider;
use App\Application\Exceptions\CentralChannelUnavailableException;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;

function centralProviderFixtureChannel(string $phoneNumberId, ChannelRole $role, ChannelStatus $status = ChannelStatus::ACTIVE): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => $status,
    ]);
}

test('devuelve el único CENTRAL activo, ignorando BUSINESS y CENTRAL inactivos', function () {
    centralProviderFixtureChannel('wamid-provider-business', ChannelRole::BUSINESS);
    centralProviderFixtureChannel('wamid-provider-central-off', ChannelRole::CENTRAL, ChannelStatus::DISCONNECTED);
    $central = centralProviderFixtureChannel('wamid-provider-central', ChannelRole::CENTRAL);

    expect((new CentralChannelProvider)->active()->is($central))->toBeTrue();
});

test('sin ningún CENTRAL activo falla en voz alta', function () {
    centralProviderFixtureChannel('wamid-provider-only-business', ChannelRole::BUSINESS);
    centralProviderFixtureChannel('wamid-provider-central-inactive', ChannelRole::CENTRAL, ChannelStatus::SUSPENDED);

    expect(fn () => (new CentralChannelProvider)->active())
        ->toThrow(CentralChannelUnavailableException::class, 'No hay ningún Channel CENTRAL activo.');
});

test('dos CENTRAL activos (solo alcanzable con SQL manual) falla en vez de elegir uno', function () {
    centralProviderFixtureChannel('wamid-provider-central-a', ChannelRole::CENTRAL);

    // Saltea la invariante del modelo a propósito: simula un INSERT manual.
    DB::table('channels')->insert([
        'provider' => ChannelProvider::META_CLOUD_API->value,
        'channel_type' => ChannelType::WHATSAPP->value,
        'role' => ChannelRole::CENTRAL->value,
        'phone_number_id' => 'wamid-provider-central-b',
        'status' => ChannelStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => (new CentralChannelProvider)->active())
        ->toThrow(CentralChannelUnavailableException::class);
});
