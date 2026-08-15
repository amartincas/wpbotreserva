<?php

use App\Application\Conversations\InboundMessageRouter;
use App\Domain\Conversational\InboundMessage;
use App\Jobs\ProcessInboundConversationMessage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    // phpunit.xml fuerza CACHE_STORE=array para el resto de la suite —
    // acá se fuerza Redis real a propósito: un mutex de aplicación solo
    // vale como evidencia si se prueba contra el backend compartido entre
    // procesos que realmente se va a usar en producción (mismo criterio que
    // ConcurrencyTest.php usando dos conexiones reales a MariaDB en vez de
    // confiar en que "el código llamó a lockForUpdate()").
    Config::set('cache.default', 'redis');
});

function lockTestMessage(string $phoneNumberId = 'wamid-lock-test', ?string $messageId = null): InboundMessage
{
    // messageId único por defecto — evita que el dedup de Cache::add()
    // filtrado por Redis (no se resetea entre tests, a diferencia de la BD
    // con RefreshDatabase) haga que un test contamine al siguiente.
    return new InboundMessage($messageId ?? 'wamid.msg-'.uniqid(), $phoneNumberId, '+573001234567', 'hola', now()->toImmutable());
}

test('Cache::lock de Redis es realmente exclusivo entre dos adquisiciones de la misma clave', function () {
    $key = 'conversation:wamid-lock-proof:+573001234567';

    $first = Cache::lock($key, 5);
    expect($first->get())->toBeTrue();

    $second = Cache::lock($key, 5);
    expect($second->get())->toBeFalse();

    $first->release();

    $third = Cache::lock($key, 5);
    expect($third->get())->toBeTrue();
    $third->release();
});

test('sin contención, el Job adquiere el lock y ejecuta el Router con el mensaje', function () {
    $message = lockTestMessage();
    $router = Mockery::mock(InboundMessageRouter::class);
    $router->shouldReceive('handle')->once()->with($message);
    App::instance(InboundMessageRouter::class, $router);

    $job = new ProcessInboundConversationMessage($message);
    $job->handle(app(InboundMessageRouter::class));
});

test('si otro proceso ya tiene el lock de la conversación, el Job nunca ejecuta el Router y falla por timeout', function () {
    $message = lockTestMessage('wamid-lock-contended');
    $lockKey = "conversation:{$message->phoneNumberId}:{$message->fromPhone}";

    $externalLock = Cache::lock($lockKey, 10);
    expect($externalLock->get())->toBeTrue();

    try {
        $router = Mockery::mock(InboundMessageRouter::class);
        $router->shouldNotReceive('handle');

        // block(1) en vez del default de producción (10s) — mismo mecanismo,
        // ventana corta para que el test no tarde 10 segundos.
        $job = new ProcessInboundConversationMessage($message, lockSeconds: 10, blockSeconds: 1);

        expect(fn () => $job->handle($router))->toThrow(LockTimeoutException::class);
    } finally {
        $externalLock->release();
    }
});

test('si Meta reenvía el mismo message_id, el segundo intento es un no-op y nunca llega al Router', function () {
    // messageId único por ejecución (no un literal fijo): la clave de
    // dedup vive en Redis, que no se resetea entre corridas de test como sí
    // hace la BD con RefreshDatabase — un literal fijo colisionaría con la
    // clave que dejó una corrida anterior de este mismo test.
    $message = lockTestMessage(messageId: 'wamid.msg-dedup-test-'.uniqid());
    $router = Mockery::mock(InboundMessageRouter::class);
    $router->shouldReceive('handle')->once()->with($message);
    App::instance(InboundMessageRouter::class, $router);

    (new ProcessInboundConversationMessage($message))->handle(app(InboundMessageRouter::class));
    // Segunda entrega del webhook con el mismo message_id (reenvío de Meta) —
    // Mockery hace fallar el test si handle() se llamara una segunda vez.
    (new ProcessInboundConversationMessage($message))->handle(app(InboundMessageRouter::class));
});

test('dos mensajes con message_id distinto se procesan ambos, sin deduplicarse entre sí', function () {
    $messageA = lockTestMessage(phoneNumberId: 'wamid-dedup-a', messageId: 'wamid.msg-dedup-a-'.uniqid());
    $messageB = lockTestMessage(phoneNumberId: 'wamid-dedup-b', messageId: 'wamid.msg-dedup-b-'.uniqid());
    $router = Mockery::mock(InboundMessageRouter::class);
    $router->shouldReceive('handle')->once()->with($messageA);
    $router->shouldReceive('handle')->once()->with($messageB);
    App::instance(InboundMessageRouter::class, $router);

    (new ProcessInboundConversationMessage($messageA))->handle(app(InboundMessageRouter::class));
    (new ProcessInboundConversationMessage($messageB))->handle(app(InboundMessageRouter::class));
});

/**
 * Regresión de un bug real encontrado en el Hito 8 (primero bloqueó la
 * confirmación de un registro de negocio, después se perdió un mensaje en
 * medio de una reserva real): la versión original reclamaba la clave de
 * dedup con Cache::add() ANTES de ejecutar el Router. Si el Router fallaba
 * (ej. un timeout transitorio de la IA), la clave quedaba reclamada para
 * siempre — el reintento automático de este mismo Job ($tries=3) chocaba
 * con su propia clave y retornaba de inmediato sin volver a intentar el
 * trabajo real, como si hubiera tenido éxito. Este test simula exactamente
 * eso: el Router falla en el primer intento (el Job debe dejar que la
 * excepción se propague, no tragársela) y el segundo intento —igual que
 * haría Laravel al reintentar automáticamente— debe volver a invocar al
 * Router de verdad, no hacer un no-op.
 */
test('si el Router falla en el primer intento, un reintento del mismo Job vuelve a invocarlo de verdad', function () {
    $message = lockTestMessage(messageId: 'wamid.msg-retry-test-'.uniqid());
    $router = Mockery::mock(InboundMessageRouter::class);
    $router->shouldReceive('handle')->once()->with($message)->andThrow(new \RuntimeException('falla transitoria simulada'));
    $router->shouldReceive('handle')->once()->with($message);
    App::instance(InboundMessageRouter::class, $router);

    $job = new ProcessInboundConversationMessage($message);

    expect(fn () => $job->handle(app(InboundMessageRouter::class)))->toThrow(\RuntimeException::class);

    // Segundo intento (reintento automático de Laravel tras la falla) — el
    // Router debe ejecutarse de nuevo; Mockery hace fallar el test si no.
    (new ProcessInboundConversationMessage($message))->handle(app(InboundMessageRouter::class));
});

test('el mismo message_id en dos Channels distintos no se deduplica entre sí — no se asume unicidad global', function () {
    $sharedMessageId = 'wamid.msg-'.uniqid();
    $messageChannelA = lockTestMessage(phoneNumberId: 'wamid-shared-id-a', messageId: $sharedMessageId);
    $messageChannelB = lockTestMessage(phoneNumberId: 'wamid-shared-id-b', messageId: $sharedMessageId);
    $router = Mockery::mock(InboundMessageRouter::class);
    $router->shouldReceive('handle')->once()->with($messageChannelA);
    $router->shouldReceive('handle')->once()->with($messageChannelB);
    App::instance(InboundMessageRouter::class, $router);

    (new ProcessInboundConversationMessage($messageChannelA))->handle(app(InboundMessageRouter::class));
    (new ProcessInboundConversationMessage($messageChannelB))->handle(app(InboundMessageRouter::class));
});
