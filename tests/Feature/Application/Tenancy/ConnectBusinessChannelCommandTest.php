<?php

use App\Application\Exceptions\BusinessChannelConnectionException;
use App\Application\Tenancy\ConnectBusinessChannelCommand;
use App\Application\Tenancy\ConnectBusinessChannelData;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;

function connectFixtureOrganization(string $ownerPhone = '+573009999999'): Organization
{
    return Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => $ownerPhone]);
}

function connectFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number' => '+57 321 3467615',
        'phone_number_id' => '1265332936673819',
        'business_account_id' => '2597666594027606',
        'status' => ChannelStatus::ACTIVE,
        'credentials' => ['access_token' => 'token-del-central'],
    ]);
}

function connectData(Organization $organization, array $overrides = []): ConnectBusinessChannelData
{
    return new ConnectBusinessChannelData(
        organization: $organization,
        phoneNumber: $overrides['phoneNumber'] ?? '+573001234567',
        phoneNumberId: $overrides['phoneNumberId'] ?? '111222333',
        businessAccountId: $overrides['businessAccountId'] ?? '444555666',
        accessToken: $overrides['accessToken'] ?? 'token-del-negocio',
    );
}

test('crea el Channel BUSINESS en PENDING_VERIFICATION, con credenciales cifradas y SIN vínculo todavía', function () {
    $organization = connectFixtureOrganization();

    $channel = (new ConnectBusinessChannelCommand)->handle(connectData($organization));

    expect($channel->role)->toBe(ChannelRole::BUSINESS);
    expect($channel->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
    expect($channel->phone_number)->toBe('+573001234567');
    expect($channel->phone_number_id)->toBe('111222333');
    expect($channel->business_account_id)->toBe('444555666');
    expect($channel->fresh()->credentials)->toBe(['access_token' => 'token-del-negocio']);
    expect(DB::table('channels')->where('id', $channel->id)->value('credentials'))->not->toContain('token-del-negocio');
    expect($channel->metadata)->toBe(['pending_organization_id' => $organization->id]);

    expect($organization->channels()->count())->toBe(0);
    expect(Channel::pendingBusinessFor($organization)->sole()->is($channel))->toBeTrue();
});

test('normaliza el número cargado con espacios o guiones a E.164', function () {
    $channel = (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization(), ['phoneNumber' => '+57 300-123 4567']));

    expect($channel->phone_number)->toBe('+573001234567');
});

test('credenciales incompletas o número inválido: rechaza sin crear nada', function (array $overrides, string $message) {
    expect(fn () => (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization(), $overrides)))
        ->toThrow(BusinessChannelConnectionException::class, $message);

    expect(Channel::count())->toBe(0);
})->with([
    'sin número' => [['phoneNumber' => ''], 'número de WhatsApp'],
    'sin phone_number_id' => [['phoneNumberId' => ' '], 'phone_number_id'],
    'sin WABA' => [['businessAccountId' => ''], 'WABA ID'],
    'sin token' => [['accessToken' => ''], 'access token'],
    'número no E.164' => [['phoneNumber' => '3001234567'], 'formato internacional'],
]);

test('una Organization con un WhatsApp ya vinculado rechaza un segundo', function () {
    $organization = connectFixtureOrganization();
    $existing = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'ya-vinculado', 'status' => ChannelStatus::ACTIVE,
    ]);
    $existing->organizations()->attach($organization->id, ['is_primary' => true]);

    expect(fn () => (new ConnectBusinessChannelCommand)->handle(connectData($organization)))
        ->toThrow(BusinessChannelAlreadyConnectedException::class);

    expect(Channel::where('phone_number_id', '111222333')->exists())->toBeFalse();
});

test('el phone_number_id del CENTRAL nunca se conecta como BUSINESS, y el CENTRAL queda intacto', function () {
    $central = connectFixtureCentral();
    $before = (array) DB::table('channels')->where('id', $central->id)->first();

    expect(fn () => (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization(), ['phoneNumberId' => '1265332936673819'])))
        ->toThrow(BusinessChannelConnectionException::class, 'CENTRAL');

    expect((array) DB::table('channels')->where('id', $central->id)->first())->toBe($before);
    expect($central->fresh()->organizations)->toHaveCount(0);
});

test('un phone_number_id ya cargado en otro Channel se rechaza', function () {
    (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization('+573008888888')));

    expect(fn () => (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization())))
        ->toThrow(BusinessChannelConnectionException::class, 'ya está cargado');

    expect(Channel::where('phone_number_id', '111222333')->count())->toBe(1);
});

test('volver a conectar con una conexión pendiente corrige sus datos en el mismo Channel, sin crear otro', function () {
    $organization = connectFixtureOrganization();
    $first = (new ConnectBusinessChannelCommand)->handle(connectData($organization, ['accessToken' => 'token-mal-copiado']));

    $second = (new ConnectBusinessChannelCommand)->handle(connectData($organization, ['phoneNumberId' => '999888777', 'accessToken' => 'token-correcto']));

    expect($second->is($first))->toBeTrue();
    expect(Channel::count())->toBe(1);
    expect($second->fresh()->phone_number_id)->toBe('999888777');
    expect($second->fresh()->credentials)->toBe(['access_token' => 'token-correcto']);
    expect($second->fresh()->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
});

test('el access token no se expone al serializar el Channel', function () {
    $channel = (new ConnectBusinessChannelCommand)->handle(connectData(connectFixtureOrganization()));

    expect($channel->toArray())->not->toHaveKey('credentials');
    expect($channel->toJson())->not->toContain('token-del-negocio');
});
