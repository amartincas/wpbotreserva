<?php

use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('una organization tiene defaults de Colombia y ciclo de vida activo', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);

    expect($org->timezone)->toBe('America/Bogota');
    expect($org->locale)->toBe('es');
    expect($org->currency)->toBe('COP');
    expect($org->is_active)->toBeTrue();
    expect($org->suspended_at)->toBeNull();
});

test('una location pertenece a una organization y hereda timezone si no tiene el propio', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create([
        'organization_id' => $org->id,
        'name' => 'Sede Chapinero',
    ]);

    expect($location->organization->is($org))->toBeTrue();
    expect($org->locations)->toHaveCount(1);
    expect($location->timezone)->toBeNull(); // cascada de Parte I §6, se resuelve en Hito 2+
    expect($location->country_code)->toBe('CO');
});

test('un channel se vincula a lo sumo a una organization — un segundo vínculo viola UNIQUE(channel_id)', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-123',
        'status' => ChannelStatus::ACTIVE,
    ]);

    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);

    $channel->organizations()->attach($orgA->id, ['is_primary' => true]);

    expect($channel->organizations)->toHaveCount(1);
    expect($orgA->channels)->toHaveCount(1);
    expect($channel->isActive())->toBeTrue();

    // Fase 6: UNIQUE(channel_id) en channel_organization (no solo
    // UNIQUE(channel_id, organization_id), que ya existía antes y nunca
    // impidió esto) es lo que hace cumplir "Channel → 0 o 1 Organization"
    // también a nivel de base de datos, no solo conversacionalmente.
    expect(fn () => $channel->organizations()->attach($orgB->id, ['is_primary' => false]))
        ->toThrow(QueryException::class);

    expect($channel->fresh()->organizations)->toHaveCount(1);
    expect($channel->fresh()->organizations->first()->is($orgA))->toBeTrue();
});

test('una organization tiene a lo sumo un channel — un segundo vínculo se rechaza (B2)', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channelA = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-multi-a',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $channelB = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-multi-b',
        'status' => ChannelStatus::ACTIVE,
    ]);

    $org->channels()->attach($channelA->id);

    // B2: una Organization tiene como máximo un Channel BUSINESS (antes de
    // la separación CENTRAL/BUSINESS podía tener varios).
    expect(fn () => $org->channels()->attach($channelB->id))
        ->toThrow(BusinessChannelAlreadyConnectedException::class);

    expect($org->fresh()->channels)->toHaveCount(1);
});

test('phone_number_id de channel es único', function () {
    Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-dup',
        'status' => ChannelStatus::ACTIVE,
    ]);

    expect(fn () => Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-dup',
        'status' => ChannelStatus::ACTIVE,
    ]))->toThrow(QueryException::class);
});

test('credentials de channel se guardan encriptadas en la base de datos', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-enc',
        'status' => ChannelStatus::ACTIVE,
        'credentials' => 'super-secret-token',
    ]);

    $raw = DB::table('channels')->where('id', $channel->id)->value('credentials');

    expect($raw)->not->toBe('super-secret-token');
    expect($channel->fresh()->credentials)->toBe('super-secret-token');
});

test('un channel nace BUSINESS por default, tanto en el modelo como en la columna', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-role-default',
        'status' => ChannelStatus::ACTIVE,
    ]);

    expect($channel->role)->toBe(ChannelRole::BUSINESS);
    expect($channel->isBusiness())->toBeTrue();
    expect($channel->isCentral())->toBeFalse();

    // Un insert que no pasa por Eloquent (ej. una fila previa a la
    // migración) también queda BUSINESS — es el default de la columna.
    $rawId = DB::table('channels')->insertGetId([
        'provider' => ChannelProvider::META_CLOUD_API->value,
        'channel_type' => ChannelType::WHATSAPP->value,
        'phone_number_id' => 'wamid-role-raw',
        'status' => ChannelStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('channels')->where('id', $rawId)->value('role'))->toBe('BUSINESS');
    expect(Channel::find($rawId)->role)->toBe(ChannelRole::BUSINESS);
});

test('role es independiente de channel_type: un channel WHATSAPP puede ser CENTRAL', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-role-central',
        'status' => ChannelStatus::ACTIVE,
    ]);

    $fresh = $channel->fresh();

    expect($fresh->role)->toBe(ChannelRole::CENTRAL);
    expect($fresh->channel_type)->toBe(ChannelType::WHATSAPP);
    expect($fresh->isCentral())->toBeTrue();
    expect($fresh->isBusiness())->toBeFalse();
});

test('el scope business() excluye los channels CENTRAL', function () {
    $business = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-scope-business',
        'status' => ChannelStatus::ACTIVE,
    ]);
    Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-scope-central',
        'status' => ChannelStatus::ACTIVE,
    ]);

    expect(Channel::business()->pluck('id')->all())->toBe([$business->id]);
});

test('owner_phone es único entre organizations', function () {
    Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573001112233']);

    expect(fn () => Organization::create(['name' => 'Spa Relax', 'owner_phone' => '+573001112233']))
        ->toThrow(QueryException::class);
});

test('varias organizations pueden no tener owner_phone (NULL no choca con el UNIQUE)', function () {
    Organization::create(['name' => 'Barbería Don Carlos']);
    Organization::create(['name' => 'Spa Relax']);

    expect(Organization::whereNull('owner_phone')->count())->toBe(2);
});
