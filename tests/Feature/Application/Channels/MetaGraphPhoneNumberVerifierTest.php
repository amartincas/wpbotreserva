<?php

use App\Application\Channels\MetaGraphPhoneNumberVerifier;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function verifierFixtureChannel(array $overrides = []): Channel
{
    return Channel::create(array_merge([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::BUSINESS,
        'phone_number' => '+573001234567',
        'phone_number_id' => '111222333',
        'business_account_id' => '444555666',
        'status' => ChannelStatus::PENDING_VERIFICATION,
        'credentials' => ['access_token' => 'token-del-negocio'],
    ], $overrides));
}

function verifierFakeGraph(array $phone, array $wabaIds, int $phoneStatus = 200, int $wabaStatus = 200): void
{
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v21.0/111222333*' => Http::response($phone, $phoneStatus),
        'graph.facebook.com/v21.0/444555666/phone_numbers*' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $wabaIds)], $wabaStatus),
    ]);
}

test('verificación exitosa: consulta GET /{phone_number_id} y la WABA con el token del Channel en el header', function () {
    verifierFakeGraph(['id' => '111222333', 'display_phone_number' => '+57 300 123 4567', 'verified_name' => 'Barbería Don Carlos'], ['000', '111222333']);

    $result = (new MetaGraphPhoneNumberVerifier)->verify(verifierFixtureChannel());

    expect($result->successful)->toBeTrue();
    expect($result->displayPhoneNumber)->toBe('+57 300 123 4567');
    expect($result->verifiedName)->toBe('Barbería Don Carlos');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v21.0/111222333?')
        && $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer token-del-negocio')
        && ! str_contains($r->url(), 'token-del-negocio'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v21.0/444555666/phone_numbers'));
});

test('el número registrado en Meta no coincide con el cargado: falla', function () {
    verifierFakeGraph(['id' => '111222333', 'display_phone_number' => '+57 311 000 0000'], ['111222333']);

    $result = (new MetaGraphPhoneNumberVerifier)->verify(verifierFixtureChannel());

    expect($result->successful)->toBeFalse();
    expect($result->reason)->toContain('no coincide');
});

test('el phone_number_id no pertenece a la WABA cargada: falla', function () {
    verifierFakeGraph(['id' => '111222333', 'display_phone_number' => '+57 300 123 4567'], ['999']);

    $result = (new MetaGraphPhoneNumberVerifier)->verify(verifierFixtureChannel());

    expect($result->successful)->toBeFalse();
    expect($result->reason)->toContain('WABA');
});

test('Meta rechaza el token o el id: falla con el code/message de Meta, nunca con el token', function () {
    verifierFakeGraph(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], [], phoneStatus: 401);

    $result = (new MetaGraphPhoneNumberVerifier)->verify(verifierFixtureChannel());

    expect($result->successful)->toBeFalse();
    expect($result->reason)->toContain('code 190')->toContain('Invalid OAuth access token.');
    expect($result->reason)->not->toContain('token-del-negocio');
});

test('credenciales incompletas: falla sin llamar a Meta', function () {
    Http::preventStrayRequests();
    Http::fake();

    $result = (new MetaGraphPhoneNumberVerifier)->verify(verifierFixtureChannel(['credentials' => []]));

    expect($result->successful)->toBeFalse();
    Http::assertNothingSent();
});
