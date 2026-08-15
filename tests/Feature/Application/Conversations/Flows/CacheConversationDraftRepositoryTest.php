<?php

use App\Application\Conversations\Flows\CacheConversationDraftRepository;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

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

/**
 * Regresión de un bug real encontrado en el Hito 8 (primer despliegue con
 * Redis real): config('cache.serializable_classes') viene en `false` por
 * defecto en Laravel — "por seguridad, ningún objeto PHP se deserializa
 * desde caché" (protección contra gadget chain attacks si se filtra
 * APP_KEY). RedisStore::unserialize() pasa ese `false` tal cual a
 * unserialize(..., ['allowed_classes' => false]), y ese modo de PHP no
 * lanza ninguna excepción: convierte SILENCIOSAMENTE cada objeto en
 * __PHP_Incomplete_Class. El draft se leía sin error visible, pero
 * RegisterOrganizationCommand fallaba después al leer ->weekday (null en
 * el objeto incompleto) contra la restricción NOT NULL de la BD — un
 * usuario real quedó con su registro de negocio trabado en el paso de
 * confirmación sin ningún mensaje de error.
 *
 * Invisible en el resto de esta suite porque phpunit.xml fuerza
 * CACHE_STORE=array (mismo motivo documentado arriba para Cache::flush) —
 * el driver array nunca pasa por RedisStore::unserialize(). Se fuerza
 * Redis real acá a propósito, mismo criterio que
 * ProcessInboundConversationMessageTest.php.
 */
test('un WeeklyScheduleSlot y una fecha CarbonImmutable sobreviven un round-trip real por Redis', function () {
    Config::set('cache.default', 'redis');
    Cache::flush();

    $session = draftRepoFixtureSession('wamid-draft-repo-redis');
    $repo = new CacheConversationDraftRepository;

    $repo->put($session, [
        'weeklySchedule' => [new WeeklyScheduleSlot(weekday: 1, startTime: '09:00', endTime: '17:00')],
        'bookingDate' => CarbonImmutable::parse('2026-08-20'),
    ]);

    $draft = $repo->get($session);

    expect($draft['weeklySchedule'][0])->toBeInstanceOf(WeeklyScheduleSlot::class);
    expect($draft['weeklySchedule'][0]->weekday)->toBe(1);
    expect($draft['bookingDate'])->toBeInstanceOf(CarbonImmutable::class);
});
