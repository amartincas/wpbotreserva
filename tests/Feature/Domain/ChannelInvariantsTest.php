<?php

use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Domain\Tenancy\Exceptions\CentralChannelLinkException;
use App\Domain\Tenancy\Exceptions\DuplicateCentralChannelException;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;

function invariantsFixtureChannel(string $phoneNumberId, ChannelRole $role = ChannelRole::BUSINESS, ChannelStatus $status = ChannelStatus::ACTIVE): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => $status,
    ]);
}

test('un CENTRAL no puede vincularse a una Organization desde ninguno de los dos lados de la relación', function () {
    $central = invariantsFixtureChannel('wamid-inv-central', ChannelRole::CENTRAL);
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    expect(fn () => $central->organizations()->attach($organization->id, ['is_primary' => true]))
        ->toThrow(CentralChannelLinkException::class);
    expect(fn () => $organization->channels()->attach($central->id, ['is_primary' => true]))
        ->toThrow(CentralChannelLinkException::class);
    expect(fn () => $central->organizations()->syncWithoutDetaching([$organization->id => ['is_primary' => true]]))
        ->toThrow(CentralChannelLinkException::class);

    expect(DB::table('channel_organization')->count())->toBe(0);
});

test('un Channel vinculado no puede pasar a CENTRAL sin desvincularse antes', function () {
    $channel = invariantsFixtureChannel('wamid-inv-linked');
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);

    $channel->role = ChannelRole::CENTRAL;

    expect(fn () => $channel->save())->toThrow(CentralChannelLinkException::class);
    expect($channel->fresh()->role)->toBe(ChannelRole::BUSINESS);
});

test('como máximo un CENTRAL ACTIVE: un segundo activo se rechaza, uno inactivo se permite', function () {
    invariantsFixtureChannel('wamid-inv-central-1', ChannelRole::CENTRAL);

    expect(fn () => invariantsFixtureChannel('wamid-inv-central-2', ChannelRole::CENTRAL))
        ->toThrow(DuplicateCentralChannelException::class);

    $inactive = invariantsFixtureChannel('wamid-inv-central-3', ChannelRole::CENTRAL, ChannelStatus::DISCONNECTED);

    expect($inactive->exists)->toBeTrue();

    $inactive->status = ChannelStatus::ACTIVE;

    expect(fn () => $inactive->save())->toThrow(DuplicateCentralChannelException::class);
    expect(Channel::where('role', ChannelRole::CENTRAL->value)->where('status', ChannelStatus::ACTIVE->value)->count())->toBe(1);
});

test('volver a guardar el único CENTRAL activo no choca consigo mismo', function () {
    $central = invariantsFixtureChannel('wamid-inv-central-self', ChannelRole::CENTRAL);

    $central->metadata = ['nota' => 'sin cambios de rol'];
    $central->save();

    expect($central->fresh()->metadata)->toBe(['nota' => 'sin cambios de rol']);
});

test('una Organization tiene como máximo un Channel BUSINESS', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $first = invariantsFixtureChannel('wamid-inv-business-1');
    $second = invariantsFixtureChannel('wamid-inv-business-2');

    $organization->channels()->attach($first->id, ['is_primary' => true]);

    expect(fn () => $organization->channels()->attach($second->id, ['is_primary' => false]))
        ->toThrow(BusinessChannelAlreadyConnectedException::class);
    expect(fn () => $second->organizations()->attach($organization->id, ['is_primary' => false]))
        ->toThrow(BusinessChannelAlreadyConnectedException::class);

    expect($organization->fresh()->channels->pluck('id')->all())->toBe([$first->id]);
});

test('volver a vincular el mismo par Channel/Organization con syncWithoutDetaching no es un segundo Channel', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel = invariantsFixtureChannel('wamid-inv-resync');

    $channel->organizations()->syncWithoutDetaching([$organization->id => ['is_primary' => true]]);
    $channel->organizations()->syncWithoutDetaching([$organization->id => ['is_primary' => true]]);

    expect(DB::table('channel_organization')->where('organization_id', $organization->id)->count())->toBe(1);
});
