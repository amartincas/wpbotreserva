<?php

use App\Application\Conversations\Flows\DateFieldExtractor;
use App\Contracts\AiServiceInterface;
use Carbon\CarbonImmutable;

function dateExtractorFakeService(string $response): AiServiceInterface
{
    return new class($response) implements AiServiceInterface
    {
        public function __construct(private readonly string $response) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            return $this->response;
        }
    };
}

function dateExtractorNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA: el chequeo de mes ambiguo tiene que cortar antes.');
        }
    };
}

test('parsea una fecha futura válida', function () {
    $tomorrow = now()->addDay()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($tomorrow), config('app.timezone'));

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($tomorrow);
});

test('acepta el día de hoy', function () {
    $today = now()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($today), config('app.timezone'));

    $result = $extractor->extract('hoy', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($today);
});

test('rechaza una fecha que ya pasó', function () {
    $yesterday = now()->subDay()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($yesterday), config('app.timezone'));

    $result = $extractor->extract('ayer', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde NO_ENCONTRADO', function () {
    $extractor = new DateFieldExtractor(dateExtractorFakeService('NO_ENCONTRADO'), config('app.timezone'));

    $result = $extractor->extract('asdkjhasd', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde algo que no es una fecha válida', function () {
    $extractor = new DateFieldExtractor(dateExtractorFakeService('no es una fecha'), config('app.timezone'));

    $result = $extractor->extract('cualquier cosa', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo (no propaga la excepción) si la llamada a la IA falla', function () {
    $throwing = new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
    $extractor = new DateFieldExtractor($throwing, config('app.timezone'));

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeFalse();
});

/**
 * Regresión de un bug real de producción: pedirle a la IA por prompt que
 * "nunca adivine el mes" ante un día suelto ("24", "el 24") no fue
 * confiable — la misma frase resolvía a veces al mes actual y a veces al
 * siguiente entre corridas reales. La detección ahora es determinista en
 * código, ANTES de llamar a la IA — por eso estos tests usan un fake que
 * revienta si se lo invoca: si algo cambia y el chequeo deja de cortar
 * antes, el test lo detecta.
 */
test('un día suelto sin mes es ambiguo y pide aclaración, sin llamar a la IA', function () {
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    foreach (['24', 'el 24', 'para el 24', 'Quiero hacer una reserva para el 24', '5'] as $texto) {
        $result = $extractor->extract($texto, []);

        expect($result->successful)->toBeFalse();
        expect($result->reason)->toContain('qué mes');
    }
});

test('con el mes explícito (en palabra o como dd/mm), no es ambiguo y sí llama a la IA', function () {
    // Fecha relativa a hoy, no hardcodeada — un "2026-08-24" fijo queda en
    // el pasado apenas el reloj real cruza esa fecha, y el extractor
    // rechaza cualquier fecha pasada (fragilidad real ya vista antes con
    // fixtures de fecha en este proyecto).
    $futureDate = now()->addDays(30)->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($futureDate), config('app.timezone'));

    $result = $extractor->extract('24 de agosto', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($futureDate);
});

test('referencias sin número (mañana, hoy, un día de la semana) nunca se marcan como ambiguas', function () {
    $tomorrow = now()->addDay()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($tomorrow), config('app.timezone'));

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeTrue();
});

/**
 * Regresión de un falso positivo real: el primer intento de este fix
 * marcaba "en 5 años" (usado deliberadamente en ReservaAgentTest para
 * forzar una fecha sin disponibilidad) como día ambiguo, cuando "5" ahí
 * no tiene nada que ver con un día del mes.
 */
test('un número seguido de una unidad de cantidad (años, días, personas...) no se confunde con un día ambiguo', function () {
    $farDate = now()->addYears(5)->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($farDate), config('app.timezone'));

    foreach (['en 5 años', 'en 3 días', 'para 2 personas', 'dentro de 4 semanas'] as $texto) {
        $result = $extractor->extract($texto, []);

        expect($result->successful)->toBeTrue();
    }
});

/**
 * Segunda ronda de casos reales reportados en producción: "lunes", "lunes
 * 31" y "08-31-2026" no se entendían (o el chequeo de mes ambiguo los
 * rechazaba de más). Los tres se resuelven ahora en código, determinista,
 * sin llamar a la IA — por eso usan dateExtractorNeverCalledAi().
 */
test('caso real: un día de la semana suelto ("lunes") resuelve al próximo, sin llamar a la IA', function () {
    $nextMonday = now()->startOfDay()->next(1); // 1 = lunes, mismo convenio 0=domingo..6=sábado
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    $result = $extractor->extract('lunes', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($nextMonday->toDateString());
});

test('caso real: "el lunes que viene" también resuelve determinista (el nombre del día alcanza, el resto es ruido)', function () {
    $nextMonday = now()->startOfDay()->next(1);
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    $result = $extractor->extract('el lunes que viene', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($nextMonday->toDateString());
});

test('caso real: día de la semana + número de día ("lunes 31") resuelve la fecha exacta sin pedir el mes', function () {
    $nextMonday = now()->startOfDay()->next(1);
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    $result = $extractor->extract("lunes {$nextMonday->day}", []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($nextMonday->toDateString());
});

test('caso real: fecha numérica con separador resuelve determinista, sin llamar a la IA, en cualquier orden día/mes que el número >12 permita distinguir', function () {
    // Construido para garantizar día > 12 (así "mes-día-año", el formato
    // real que reportó el bug, queda inequívocamente resuelto) y a la vez
    // en el futuro sin importar cuándo corra el test.
    $target = now()->startOfMonth()->addMonths(2)->addDays(24)->startOfDay();
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    $diaMesAno = sprintf('%02d-%02d-%04d', $target->day, $target->month, $target->year);
    $mesDiaAno = sprintf('%02d-%02d-%04d', $target->month, $target->day, $target->year);

    foreach ([$diaMesAno, $mesDiaAno] as $texto) {
        $result = $extractor->extract($texto, []);

        expect($result->successful)->toBeTrue();
        expect($result->value->toDateString())->toBe($target->toDateString());
    }
});

test('fecha numérica con ambos componentes <=12 (ambiguo de verdad) asume día-mes-año, no mes-día-año', function () {
    $target = now()->startOfMonth()->addMonths(3)->addDays(7)->startOfDay(); // día=8, mes<=12
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), config('app.timezone'));

    $result = $extractor->extract(sprintf('%02d-%02d-%04d', $target->day, $target->month, $target->year), []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($target->toDateString());
});

/**
 * Corrección transversal de timezone: "hoy"/"mañana" y los días de semana
 * deterministas deben calcularse con el timezone recibido en el
 * constructor, nunca con el del servidor — probado forzando un timezone de
 * Organization bien distinto al de la app (config('app.timezone') es
 * America/Bogota en este proyecto).
 */
test('"hoy" usa el timezone recibido, no el del servidor', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 23:30:00', 'UTC'));

    // "hoy" no es un patrón determinista acá (solo weekday/weekday+día/fecha
    // numérica lo son) — pasa por la IA, cuyo prompt recibe el "hoy" ya
    // corregido (ver el test del prompt más abajo); acá se fija el fake
    // para que devuelva ese mismo valor esperado, y se verifica que sea el
    // de Tokyo, no el del servidor.
    // A las 23:30 UTC del 15/10, en Asia/Tokyo (UTC+9) ya es 16/10.
    $expected = CarbonImmutable::now('Asia/Tokyo')->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($expected), 'Asia/Tokyo');

    $result = $extractor->extract('hoy', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($expected);
    expect($expected)->not->toBe(CarbonImmutable::now(config('app.timezone'))->toDateString());

    CarbonImmutable::setTestNow();
});

test('un día de semana suelto ("lunes") resuelve relativo al timezone recibido, no al del servidor', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 23:30:00', 'UTC'));

    $expectedNextMonday = CarbonImmutable::now('Asia/Tokyo')->startOfDay()->next(1);
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), 'Asia/Tokyo');

    $result = $extractor->extract('lunes', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($expectedNextMonday->toDateString());

    CarbonImmutable::setTestNow();
});

test('el "hoy" entregado al prompt de IA usa el timezone recibido, no el del servidor', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 23:30:00', 'UTC'));

    $capturedPrompt = null;
    $ai = new class($capturedPrompt) implements AiServiceInterface
    {
        public function __construct(private mixed &$capturedPrompt) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            $this->capturedPrompt = $systemPrompt;

            return CarbonImmutable::now('Asia/Tokyo')->addDays(3)->toDateString();
        }
    };
    $extractor = new DateFieldExtractor($ai, 'Asia/Tokyo');

    $extractor->extract('en unos días', []);

    $expectedToday = CarbonImmutable::now('Asia/Tokyo')->toDateString();
    expect($capturedPrompt)->toContain("Hoy es {$expectedToday}");

    CarbonImmutable::setTestNow();
});

/**
 * Corrección DateFieldExtractor/ruta IA: createFromFormat() sin timezone
 * explícito etiquetaba el resultado con config('app.timezone') (el del
 * servidor), nunca con el de la Organization — un defecto que .toDateString()
 * no puede detectar (los dígitos del string son los mismos sin importar la
 * etiqueta de timezone). Por eso acá se verifica timezoneName directamente,
 * no solo la fecha en texto.
 */
test('la fecha resuelta vía IA queda etiquetada con el timezone recibido, no con el del servidor', function () {
    $futureDate = CarbonImmutable::now('Asia/Tokyo')->addDays(30)->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($futureDate), 'Asia/Tokyo');

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->timezoneName)->toBe('Asia/Tokyo');
    expect($result->value->timezoneName)->not->toBe(config('app.timezone'));
});

/**
 * Corrección DateFieldExtractor/segundo punto: matchNumericDate()
 * (createFromDate(), la vía determinista de "31-08-2026") tenía el mismo
 * defecto, sin pasar por IA.
 */
test('la fecha numérica con separador queda etiquetada con el timezone recibido, no con el del servidor', function () {
    // Año calculado dinámicamente (siempre en el futuro, mismo criterio que
    // el resto del archivo) — día 31 fuerza día-mes-año sin ambigüedad.
    $futureYear = CarbonImmutable::now('Asia/Tokyo')->addYears(2)->year;
    $extractor = new DateFieldExtractor(dateExtractorNeverCalledAi(), 'Asia/Tokyo');

    $result = $extractor->extract("31-08-{$futureYear}", []);

    expect($result->successful)->toBeTrue();
    expect($result->value->timezoneName)->toBe('Asia/Tokyo');
    expect($result->value->timezoneName)->not->toBe(config('app.timezone'));
});
