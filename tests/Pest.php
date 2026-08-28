<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Guarda dura: nunca correr contra una base que no sea de test
|--------------------------------------------------------------------------
|
| Incidente real (2026-08-20 y de nuevo el 2026-08-21): phpunit.xml
| declaraba DB_DATABASE=wpbotreserva_test con force="true", pero en la
| práctica el proceso siguió conectándose a la base real de staging —
| confirmado corriendo un test que imprimía DB::connection()->getDatabaseName()
| y daba "wpbotreserva", no "wpbotreserva_test". No investigar más por qué
| force="true" no alcanzó — la garantía tiene que vivir en un lugar que no
| dependa de que esa precedencia de PHPUnit funcione como se espera.
|
| Este chequeo corre ANTES de que Pest registre RefreshDatabase (que es
| justamente lo que borra/re-migra todo) — es la primera línea de código
| de todo el archivo, a propósito. Revisa el valor crudo de entorno
| (getenv/$_ENV/$_SERVER, las tres fuentes posibles) sin pasar por
| Laravel/config — así funciona incluso si el bug real terminara siendo
| algo en la capa de config, no solo en el entorno.
|
*/
$__dbDatabase = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? null));

if ($__dbDatabase !== 'wpbotreserva_test') {
    fwrite(STDERR, "\n\n¡ABORTADO! DB_DATABASE resolvió a \"{$__dbDatabase}\", no \"wpbotreserva_test\".\n");
    fwrite(STDERR, "Correr los tests así arriesga borrar datos reales (RefreshDatabase). No continuar.\n\n");
    exit(1);
}
unset($__dbDatabase);

/*
|--------------------------------------------------------------------------
| Guarda dura: nunca correr contra un cache store compartido (Redis real)
|--------------------------------------------------------------------------
|
| Incidente real (2026-08-28, Fase 2 de mensajes editables): un test que
| editaba un BotMessage y esperaba que Cache::forget() invalidara la
| lectura siguiente contaminó el Redis REAL de dev — mismo motivo que el
| guard de arriba: phpunit.xml declara CACHE_STORE=array con force="true",
| pero en la práctica el proceso siguió usando "redis" (heredado del
| entorno real vía env_file de Docker). Síntoma: tests que ni siquiera
| tocan bot_messages fallaban con el texto de OTRO test — Cache::remember()
| nunca hacía miss, porque el valor mutado seguía en Redis después de que
| la transacción de BD de ese test se revirtiera (RefreshDatabase revierte
| la BD, nunca el cache). Mismo criterio que arriba: no depender de que esa
| precedencia de PHPUnit funcione, verificarlo acá.
|
*/
$__cacheStore = getenv('CACHE_STORE') ?: ($_ENV['CACHE_STORE'] ?? ($_SERVER['CACHE_STORE'] ?? null));

if ($__cacheStore !== 'array') {
    fwrite(STDERR, "\n\n¡ABORTADO! CACHE_STORE resolvió a \"{$__cacheStore}\", no \"array\".\n");
    fwrite(STDERR, "Correr los tests así arriesga contaminar el cache compartido (Redis) con datos de test.\n\n");
    exit(1);
}
unset($__cacheStore);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
