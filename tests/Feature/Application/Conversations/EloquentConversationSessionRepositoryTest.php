<?php

use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

function repoFixtureChannel(string $phoneNumberId = 'wamid-repo'): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

test('findOrCreateFor crea una sesión nueva si no existe', function () {
    $channel = repoFixtureChannel();
    $repo = new EloquentConversationSessionRepository;

    $session = $repo->findOrCreateFor($channel, '+573001234567');

    expect($session->exists)->toBeTrue();
    expect($session->channel_id)->toBe($channel->id);
    expect($session->customer_phone->value())->toBe('+573001234567');
});

test('findOrCreateFor devuelve la misma sesión existente en vez de duplicarla', function () {
    $channel = repoFixtureChannel();
    $repo = new EloquentConversationSessionRepository;

    $first = $repo->findOrCreateFor($channel, '+573001234567');
    $second = $repo->findOrCreateFor($channel, '+573001234567');

    expect($second->id)->toBe($first->id);
    expect(ConversationSession::count())->toBe(1);
});

test('un mismo teléfono puede tener una sesión distinta por cada Channel', function () {
    $channelA = repoFixtureChannel('wamid-repo-a');
    $channelB = repoFixtureChannel('wamid-repo-b');
    $repo = new EloquentConversationSessionRepository;

    $sessionA = $repo->findOrCreateFor($channelA, '+573001234567');
    $sessionB = $repo->findOrCreateFor($channelB, '+573001234567');

    expect($sessionA->id)->not->toBe($sessionB->id);
});

test('attachOrganization solo escribe si organization_id cambió', function () {
    $channel = repoFixtureChannel();
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $repo = new EloquentConversationSessionRepository;
    $session = $repo->findOrCreateFor($channel, '+573001234567');

    $repo->attachOrganization($session, $org);

    expect($session->fresh()->organization_id)->toBe($org->id);
});

test('recordIntent persiste el Intent y null limpia la continuidad', function () {
    $channel = repoFixtureChannel();
    $repo = new EloquentConversationSessionRepository;
    $session = $repo->findOrCreateFor($channel, '+573001234567');

    $repo->recordIntent($session, Intent::Reserva);
    expect($session->fresh()->current_intent)->toBe('reserva');

    $repo->recordIntent($session, null);
    expect($session->fresh()->current_intent)->toBeNull();
});

/**
 * Defensa en profundidad (validado antes del Hito 4): el mutex de Redis del
 * Job es la protección primaria contra dos mensajes simultáneos; esto prueba
 * que, aunque el lock fallara, la BD sigue siendo la fuente de verdad y
 * jamás duplica una sesión — mismo espíritu que ConcurrencyTest.php
 * (Hito 2). Ambos inserts van por la conexión secundaria, comiteados de
 * verdad: RefreshDatabase envuelve la conexión principal en una transacción
 * sin comitear, invisible para otra conexión — un insert ahí no dispararía
 * el unique constraint contra una fila que la otra conexión no puede ver
 * todavía (mismo detalle ya documentado en ConcurrencyTest.php).
 */
test('el unique(channel_id, customer_phone) impide duplicar una sesión incluso sin el mutex de aplicación', function () {
    Config::set('database.connections.mariadb_secondary', Config::get('database.connections.mariadb'));
    $secondary = DB::connection('mariadb_secondary');

    $channelId = $secondary->table('channels')->insertGetId([
        'provider' => 'meta_cloud_api',
        'channel_type' => 'whatsapp',
        'phone_number_id' => 'wamid-repo-race',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $secondary->table('conversation_sessions')->insert([
            'channel_id' => $channelId,
            'customer_phone' => '+573001234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $secondary->table('conversation_sessions')->insert([
            'channel_id' => $channelId,
            'customer_phone' => '+573001234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    } finally {
        $secondary->table('conversation_sessions')->where('channel_id', $channelId)->delete();
        $secondary->table('channels')->where('id', $channelId)->delete();
        DB::purge('mariadb_secondary');
    }
});
