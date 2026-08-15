<?php

/**
 * Regresión de un bug real encontrado en el Hito 8 (primer despliegue con
 * un .env real): env() devuelve el default de PHP tal cual (un int) solo
 * cuando la variable de entorno NO existe. En cuanto la variable SÍ está
 * seteada en un .env real — como en cualquier despliegue real, a
 * diferencia de este entorno de test donde nunca se había seteado — env()
 * siempre devuelve un string, y Carbon::addHours()/addMinutes() exigen
 * int|float. Sin el cast (int) explícito en config/conversations.php, el
 * pipeline entero fallaba con un TypeError apenas llegaba un mensaje real.
 *
 * Este test fuerza la variable de entorno como string ANTES de requerir el
 * archivo de config directamente (no vía config() de Laravel, que ya la
 * cachea al boot) — es la única forma de reproducir de verdad la condición
 * que rompió en el servidor.
 */
test('message_dedup_hours es int incluso cuando la variable de entorno viene seteada como string', function () {
    putenv('CONVERSATION_MESSAGE_DEDUP_HOURS=48');

    $config = require base_path('config/conversations.php');

    expect($config['message_dedup_hours'])->toBeInt();
    expect($config['message_dedup_hours'])->toBe(48);

    putenv('CONVERSATION_MESSAGE_DEDUP_HOURS');
});

test('continuity_ttl_minutes es int incluso cuando la variable de entorno viene seteada como string', function () {
    putenv('CONVERSATION_CONTINUITY_TTL_MINUTES=240');

    $config = require base_path('config/conversations.php');

    expect($config['continuity_ttl_minutes'])->toBeInt();
    expect($config['continuity_ttl_minutes'])->toBe(240);

    putenv('CONVERSATION_CONTINUITY_TTL_MINUTES');
});
