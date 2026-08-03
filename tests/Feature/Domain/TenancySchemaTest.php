<?php

use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
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

test('un channel puede estar vinculado a varias organizations (N:N por diseño)', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-123',
        'status' => ChannelStatus::ACTIVE,
    ]);

    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);

    $channel->organizations()->attach([$orgA->id, $orgB->id]);

    expect($channel->organizations)->toHaveCount(2);
    expect($orgA->channels)->toHaveCount(1);
    expect($channel->isActive())->toBeTrue();
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
