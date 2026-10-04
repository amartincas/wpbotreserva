<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;

const PROMOTE_OWNER_PHONE = '+573054097492';
const PROMOTE_CUSTOMER_PHONE = '+573001234567';

/**
 * Espeja el estado real de staging antes de la promoción: un único Channel
 * BUSINESS ACTIVE con credenciales, vinculado a la Organization piloto, con
 * una sesión del owner y otra de un cliente, ambas memoizadas a esa
 * Organization y con un flujo a medias.
 */
function promoteFixture(): array
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number' => '+57 321 3467615',
        'phone_number_id' => '1265332936673819',
        'business_account_id' => '2597666594027606',
        'status' => ChannelStatus::ACTIVE,
        'credentials' => ['access_token' => 'token-real-de-meta'],
        'metadata' => ['origen' => 'staging'],
    ]);
    $organization = Organization::create(['name' => '2M Comunicaciones', 'owner_phone' => PROMOTE_OWNER_PHONE]);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);

    $ownerSession = ConversationSession::create([
        'channel_id' => $channel->id,
        'organization_id' => $organization->id,
        'customer_phone' => PROMOTE_OWNER_PHONE,
        'current_intent' => 'gestion_negocio',
    ]);
    $customerSession = ConversationSession::create([
        'channel_id' => $channel->id,
        'organization_id' => $organization->id,
        'customer_phone' => PROMOTE_CUSTOMER_PHONE,
        'current_intent' => 'reserva',
    ]);

    $drafts = app(ConversationDraftRepositoryInterface::class);
    $drafts->put($ownerSession, ['paso' => 'servicio']);
    $drafts->put($customerSession, ['paso' => 'fecha']);

    return compact('channel', 'organization', 'ownerSession', 'customerSession');
}

/**
 * Otro negocio con su propio BUSINESS, vinculado y con una sesión en curso —
 * la promoción nunca debe tocarlo.
 */
function promoteFixtureOtherBusiness(): array
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-otro-negocio',
        'status' => ChannelStatus::ACTIVE,
        'credentials' => ['access_token' => 'token-otro-negocio'],
    ]);
    $organization = Organization::create(['name' => 'Spa Relax', 'owner_phone' => '+573008888888']);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);
    $session = ConversationSession::create([
        'channel_id' => $channel->id,
        'organization_id' => $organization->id,
        'customer_phone' => PROMOTE_CUSTOMER_PHONE,
        'current_intent' => 'reserva',
    ]);
    app(ConversationDraftRepositoryInterface::class)->put($session, ['paso' => 'hora']);

    return compact('channel', 'organization', 'session');
}

function promoteRawChannel(int $channelId): array
{
    return (array) DB::table('channels')->where('id', $channelId)->first();
}

test('sin --apply es un dry-run: describe el plan y no modifica nada', function () {
    $fixture = promoteFixture();
    $before = promoteRawChannel($fixture['channel']->id);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819'])
        ->expectsOutputToContain('Rol actual: BUSINESS → CENTRAL')
        ->expectsOutputToContain('2M Comunicaciones')
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    expect(promoteRawChannel($fixture['channel']->id))->toBe($before);
    expect(DB::table('channel_organization')->where('channel_id', $fixture['channel']->id)->count())->toBe(1);
    expect($fixture['ownerSession']->fresh()->current_intent)->toBe('gestion_negocio');
    expect($fixture['customerSession']->fresh()->organization_id)->toBe($fixture['organization']->id);
    expect(app(ConversationDraftRepositoryInterface::class)->get($fixture['customerSession']))->toBe(['paso' => 'fecha']);
});

test('--apply desvincula la Organization y convierte el Channel en CENTRAL', function () {
    $fixture = promoteFixture();

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    $channel = $fixture['channel']->fresh();

    expect($channel->role)->toBe(ChannelRole::CENTRAL);
    expect($channel->organizations()->count())->toBe(0);
    expect(DB::table('channel_organization')->where('channel_id', $channel->id)->count())->toBe(0);
    expect(Organization::whereKey($fixture['organization']->id)->exists())->toBeTrue();
});

test('--apply conserva credenciales y configuración Meta, byte a byte', function () {
    $fixture = promoteFixture();
    $before = promoteRawChannel($fixture['channel']->id);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    $after = promoteRawChannel($fixture['channel']->id);

    foreach (['provider', 'channel_type', 'phone_number', 'phone_number_id', 'business_account_id', 'status', 'credentials', 'metadata'] as $column) {
        expect($after[$column])->toBe($before[$column]);
    }
    expect($after['role'])->toBe('CENTRAL');
    expect($fixture['channel']->fresh()->credentials)->toBe(['access_token' => 'token-real-de-meta']);
});

test('--apply limpia las sesiones del Channel: intent y draft en todas, Organization solo se conserva en la del owner', function () {
    $fixture = promoteFixture();
    $drafts = app(ConversationDraftRepositoryInterface::class);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    $owner = $fixture['ownerSession']->fresh();
    $customer = $fixture['customerSession']->fresh();

    expect($owner->current_intent)->toBeNull();
    expect($owner->organization_id)->toBe($fixture['organization']->id);
    expect($customer->current_intent)->toBeNull();
    expect($customer->organization_id)->toBeNull();
    expect($drafts->get($owner))->toBe([]);
    expect($drafts->get($customer))->toBe([]);
});

test('--apply no toca otros Channels, Organizations ni sesiones', function () {
    $fixture = promoteFixture();
    $other = promoteFixtureOtherBusiness();
    $otherBefore = promoteRawChannel($other['channel']->id);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    expect(promoteRawChannel($other['channel']->id))->toBe($otherBefore);
    expect($other['channel']->fresh()->organizations->pluck('id')->all())->toBe([$other['organization']->id]);
    expect($other['session']->fresh()->current_intent)->toBe('reserva');
    expect($other['session']->fresh()->organization_id)->toBe($other['organization']->id);
    expect(app(ConversationDraftRepositoryInterface::class)->get($other['session']))->toBe(['paso' => 'hora']);
    expect(Organization::count())->toBe(2);
});

test('un Channel inexistente falla sin modificar nada', function () {
    $fixture = promoteFixture();

    $this->artisan('channels:promote-central', ['phone_number_id' => 'no-existe', '--apply' => true])
        ->expectsOutputToContain('No existe ningún Channel')
        ->assertFailed();

    expect($fixture['channel']->fresh()->role)->toBe(ChannelRole::BUSINESS);
    expect(DB::table('channel_organization')->count())->toBe(1);
});

test('si ya existe otro CENTRAL, falla sin modificar nada (nunca dos CENTRAL)', function () {
    $fixture = promoteFixture();
    $existingCentral = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-central-previo',
        'status' => ChannelStatus::DISCONNECTED,
    ]);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->expectsOutputToContain("Ya existe otro Channel CENTRAL (#{$existingCentral->id})")
        ->assertFailed();

    expect($fixture['channel']->fresh()->role)->toBe(ChannelRole::BUSINESS);
    expect(DB::table('channel_organization')->where('channel_id', $fixture['channel']->id)->count())->toBe(1);
    expect($fixture['ownerSession']->fresh()->current_intent)->toBe('gestion_negocio');
    expect(Channel::where('role', ChannelRole::CENTRAL->value)->count())->toBe(1);
});

test('un Channel que no está ACTIVE no se promueve', function () {
    $fixture = promoteFixture();
    $fixture['channel']->update(['status' => ChannelStatus::DISCONNECTED]);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->expectsOutputToContain('no está ACTIVE')
        ->assertFailed();

    expect($fixture['channel']->fresh()->role)->toBe(ChannelRole::BUSINESS);
    expect(DB::table('channel_organization')->where('channel_id', $fixture['channel']->id)->count())->toBe(1);
});

test('una falla a mitad de la promoción revierte todo: el vínculo y las sesiones vuelven a su estado previo', function () {
    $fixture = promoteFixture();

    // Falla justo al guardar el rol — después de haber borrado el vínculo y
    // antes de limpiar sesiones. Todo lo anterior tiene que revertirse.
    Channel::saving(function (Channel $channel) {
        if ($channel->isDirty('role')) {
            throw new RuntimeException('falla simulada al guardar el rol');
        }
    });

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->expectsOutputToContain('Promoción revertida')
        ->assertFailed();

    expect($fixture['channel']->fresh()->role)->toBe(ChannelRole::BUSINESS);
    expect(DB::table('channel_organization')->where('channel_id', $fixture['channel']->id)->count())->toBe(1);
    expect($fixture['customerSession']->fresh()->organization_id)->toBe($fixture['organization']->id);
    expect($fixture['customerSession']->fresh()->current_intent)->toBe('reserva');
    expect(app(ConversationDraftRepositoryInterface::class)->get($fixture['customerSession']))->toBe(['paso' => 'fecha']);
});

test('correrlo de nuevo sobre un Channel ya CENTRAL no hace nada (idempotente, no vuelve a limpiar sesiones)', function () {
    $fixture = promoteFixture();

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    // Entre una corrida y otra, un owner nuevo arranca su onboarding en el CENTRAL.
    $onboarding = ConversationSession::create([
        'channel_id' => $fixture['channel']->id,
        'customer_phone' => '+573007777777',
        'current_intent' => 'registro_negocio',
    ]);
    app(ConversationDraftRepositoryInterface::class)->put($onboarding, ['organizationName' => 'Nuevo']);
    $before = promoteRawChannel($fixture['channel']->id);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->expectsOutputToContain('ya es CENTRAL')
        ->assertSuccessful();

    expect(promoteRawChannel($fixture['channel']->id))->toBe($before);
    expect($onboarding->fresh()->current_intent)->toBe('registro_negocio');
    expect(app(ConversationDraftRepositoryInterface::class)->get($onboarding))->toBe(['organizationName' => 'Nuevo']);
    expect(Channel::where('role', ChannelRole::CENTRAL->value)->count())->toBe(1);
});

test('un CENTRAL que aparece vinculado (SQL manual) se reporta como inconsistente y no se repara solo', function () {
    $fixture = promoteFixture();

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->assertSuccessful();

    // Saltea la invariante del pivot a propósito: simula un INSERT manual.
    DB::table('channel_organization')->insert([
        'channel_id' => $fixture['channel']->id,
        'organization_id' => $fixture['organization']->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('channels:promote-central', ['phone_number_id' => '1265332936673819', '--apply' => true])
        ->expectsOutputToContain('estado inconsistente')
        ->assertFailed();

    expect(DB::table('channel_organization')->where('channel_id', $fixture['channel']->id)->count())->toBe(1);
});
