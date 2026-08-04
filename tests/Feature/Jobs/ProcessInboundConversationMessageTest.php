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

function lockTestMessage(string $phoneNumberId = 'wamid-lock-test'): InboundMessage
{
    return new InboundMessage($phoneNumberId, '+573001234567', 'hola', now()->toImmutable());
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
