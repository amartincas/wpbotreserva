<?php

use App\Application\Conversations\Flows\CacheConversationDraftRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\Cache;

/**
 * El driver de cache 'array' (default de test, phpunit.xml) vive para todo
 * el proceso — no se resetea por test como la BD con RefreshDatabase. Como
 * conversation_sessions.id puede repetirse entre tests (RefreshDatabase
 * revierte la transacción, incluida la asignación de auto_increment), sin
 * este flush un draft escrito por un test aparecería "ya guardado" en el
 * siguiente que reutiliza el mismo id — puramente un artefacto de test, no
 * algo que pueda pasar en producción (ahí los IDs nunca se repiten).
 */
beforeEach(fn () => Cache::flush());

function draftRepoFixtureSession(string $phoneNumberId = 'wamid-draft-repo'): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
}

test('get devuelve un array vacío si no hay draft guardado', function () {
    $session = draftRepoFixtureSession();
    $repo = new CacheConversationDraftRepository;

    expect($repo->get($session))->toBe([]);
});

test('put persiste el draft y get lo recupera', function () {
    $session = draftRepoFixtureSession();
    $repo = new CacheConversationDraftRepository;

    $repo->put($session, ['nombre' => 'Carlos']);

    expect($repo->get($session))->toBe(['nombre' => 'Carlos']);
});

test('forget elimina el draft', function () {
    $session = draftRepoFixtureSession();
    $repo = new CacheConversationDraftRepository;

    $repo->put($session, ['nombre' => 'Carlos']);
    $repo->forget($session);

    expect($repo->get($session))->toBe([]);
});

test('dos sesiones distintas nunca comparten draft', function () {
    $sessionA = draftRepoFixtureSession('wamid-draft-repo-a');
    $sessionB = draftRepoFixtureSession('wamid-draft-repo-b');
    $repo = new CacheConversationDraftRepository;

    $repo->put($sessionA, ['nombre' => 'Carlos']);

    expect($repo->get($sessionB))->toBe([]);
});
