<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Prueba directa del mecanismo de concurrencia (Parte XI punto 1) contra
 * MariaDB real — no solo "el test pasó", sino verificar que el lock
 * efectivamente bloquea una segunda conexión de verdad, con dos conexiones
 * PDO reales a la misma base (mismo espíritu que "probado con SQL crudo
 * antes de confiar en los tests" del Hito 1).
 *
 * Detalle importante: `RefreshDatabase` envuelve cada test en una
 * transacción sin comitear para poder revertirla al final (rápido, pero
 * invisible para OTRA conexión). Un lock real entre dos conexiones necesita
 * una fila realmente comiteada — por eso acá se inserta con SQL crudo desde
 * la conexión secundaria (fuera de esa transacción envolvente) y se limpia
 * a mano al final, en vez de apoyarse en el rollback automático.
 *
 * Lo que esto prueba: que `lockForUpdate()` serializa dos transacciones
 * concurrentes sobre el mismo recurso. Lo que NO prueba (y por qué no se
 * intenta acá): ejecutar BookingScheduler::schedule() en paralelo real
 * requeriría procesos/hilos separados, frágil en este entorno. Esta prueba
 * + las pruebas secuenciales de BookingSchedulerTest (que prueban que, UNA
 * VEZ bajo lock, la revalidación efectivamente rechaza el conflicto) dan
 * cobertura real del mecanismo completo en conjunto.
 */
test('lockForUpdate sobre un resource bloquea genuinamente una segunda conexión concurrente', function () {
    Config::set('database.connections.mariadb_secondary', Config::get('database.connections.mariadb'));
    $secondary = DB::connection('mariadb_secondary');

    $orgId = $secondary->table('organizations')->insertGetId([
        'name' => 'Barbería Don Carlos',
        'timezone' => 'America/Bogota',
        'locale' => 'es',
        'currency' => 'COP',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resourceId = $secondary->table('resources')->insertGetId([
        'organization_id' => $orgId,
        'resource_type' => 'HUMAN',
        'display_name' => 'Carlos',
        'capacity' => 1,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('resources')->where('id', $resourceId)->lockForUpdate()->first();

        $secondary->beginTransaction();
        $start = microtime(true);
        $blocked = false;

        try {
            $secondary->table('resources')->where('id', $resourceId)->lockForUpdate()->first();
        } catch (Throwable $e) {
            // ER_LOCK_WAIT_TIMEOUT — la segunda conexión esperó y no pudo tomar el lock.
            $blocked = true;
        }

        $elapsedSeconds = microtime(true) - $start;

        $secondary->rollBack();
        DB::rollBack();

        expect($blocked)->toBeTrue();
        expect($elapsedSeconds)->toBeGreaterThan(0.9);
    } finally {
        // Estas filas se insertaron comiteadas (fuera de la transacción de
        // RefreshDatabase) a propósito — hay que limpiarlas a mano.
        $secondary->table('resources')->where('id', $resourceId)->delete();
        $secondary->table('organizations')->where('id', $orgId)->delete();
        DB::purge('mariadb_secondary');
    }
});
