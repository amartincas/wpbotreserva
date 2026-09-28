<?php

use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;

function weeklyScheduleFakeService(string $response): AiServiceInterface
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

// Para probar que el camino determinista corta el flujo ANTES de tocar la
// IA: si algo se le llega a preguntar a este fake, la excepción hace fallar
// el test.
function weeklyScheduleThrowingFakeService(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('no debería haberse llamado a la IA: el parser determinista debía resolverlo');
        }
    };
}

test('parsea un JSON válido en un array de WeeklyScheduleSlot', function () {
    $json = json_encode([
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
    ]);
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService($json));

    $result = $extractor->extract('Lunes y Martes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(2);
    expect($result->value[0])->toBeInstanceOf(WeeklyScheduleSlot::class);
    expect($result->value[0]->weekday)->toBe(1);
    expect($result->value[0]->startTime)->toBe('09:00');
    expect($result->value[1]->weekday)->toBe(2);
});

test('devuelve fallo cuando la IA responde NO_ENCONTRADO', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('NO_ENCONTRADO'));

    $result = $extractor->extract('no sé', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde un JSON inválido', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('{esto no es json'));

    // Frase libre, sin patrón "día de hora" reconocible en forma
    // determinista, para que este caso siga ejerciendo el camino de IA.
    $result = $extractor->extract('cualquier día que puedas', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde un array vacío', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('[]'));

    $result = $extractor->extract('nunca atendemos', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando un slot no tiene la forma esperada', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('[{"weekday": 1}]'));

    $result = $extractor->extract('Lunes', []);

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
    $extractor = new WeeklyScheduleFieldExtractor($throwing);

    // Mismo motivo que el test de JSON inválido: frase libre para que
    // efectivamente se llegue a invocar la IA (y no el parser determinista).
    $result = $extractor->extract('coordinamos por privado', []);

    expect($result->successful)->toBeFalse();
});

// --- Parser determinista (sin tocar la IA) ---

test('resuelve un solo día con un rango horario sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(1);
    expect($result->value[0]->weekday)->toBe(1);
    expect($result->value[0]->startTime)->toBe('09:00');
    expect($result->value[0]->endTime)->toBe('17:00');
});

test('resuelve un rango de días sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes a Viernes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(5);
    expect(array_map(fn ($slot) => $slot->weekday, $result->value))->toBe([1, 2, 3, 4, 5]);
    expect($result->value[0]->startTime)->toBe('09:00');
    expect($result->value[0]->endTime)->toBe('17:00');
});

test('resuelve una lista de días separados por "y" sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes y Martes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(2);
    expect($result->value[0]->weekday)->toBe(1);
    expect($result->value[1]->weekday)->toBe(2);
});

test('resuelve el caso real reportado: lista de días sin "Lunes" al inicio, con horas ambiguas y una franja doble', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract(
        'Martes de 2 a 6, miércoles de 8 a 12, jueves de 8 a 12 y de 2 a 6, viernes de 8 a 2',
        []
    );

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(5);

    // Martes de 2 a 6 → tarde (14 a 18), no madrugada.
    expect($result->value[0]->weekday)->toBe(2);
    expect($result->value[0]->startTime)->toBe('14:00');
    expect($result->value[0]->endTime)->toBe('18:00');

    // Miércoles de 8 a 12 → mañana literal.
    expect($result->value[1]->weekday)->toBe(3);
    expect($result->value[1]->startTime)->toBe('08:00');
    expect($result->value[1]->endTime)->toBe('12:00');

    // Jueves: dos franjas, mañana y tarde.
    expect($result->value[2]->weekday)->toBe(4);
    expect($result->value[2]->startTime)->toBe('08:00');
    expect($result->value[2]->endTime)->toBe('12:00');
    expect($result->value[3]->weekday)->toBe(4);
    expect($result->value[3]->startTime)->toBe('14:00');
    expect($result->value[3]->endTime)->toBe('18:00');

    // Viernes de 8 a 2 → 8 de la mañana a 2 de la tarde (nunca cruza medianoche).
    expect($result->value[4]->weekday)->toBe(5);
    expect($result->value[4]->startTime)->toBe('08:00');
    expect($result->value[4]->endTime)->toBe('14:00');
});

// --- Post-E2E Fase 1 (Hallazgo 4): sufijos AM/PM, sin llamar a la IA ---

test('reconoce el caso real de esta E2E: rango de días con "am"/"pm" y franja doble, sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    // "sábado" en singular a propósito — el mensaje real de la E2E decía
    // "sábados" (plural), que NO matchea SpanishWeekdayNames::indexOf()
    // (solo reconoce formas singulares, igual que el resto de los días en
    // todos los tests de este archivo) — esa es una limitación preexistente
    // de parseDays(), separada del soporte am/pm de este Hallazgo 4, y
    // fuera de alcance de esta ronda (no se pidió extender el
    // reconocimiento de plurales).
    $result = $extractor->extract(
        'Lunes a viernes de 8am a 12pm y de 2 pm a 5pm, sábado de 9am a 1pm',
        []
    );

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(11); // 5 días x 2 franjas + sábado x 1

    // Lunes, primera franja: 8am a 12pm -> 08:00-12:00.
    expect($result->value[0]->weekday)->toBe(1);
    expect($result->value[0]->startTime)->toBe('08:00');
    expect($result->value[0]->endTime)->toBe('12:00');

    // Lunes, segunda franja: 2pm a 5pm -> 14:00-17:00.
    expect($result->value[1]->startTime)->toBe('14:00');
    expect($result->value[1]->endTime)->toBe('17:00');

    // Sábado: 9am a 1pm -> 09:00-13:00 (último slot de la lista).
    $sabado = $result->value[array_key_last($result->value)];
    expect($sabado->weekday)->toBe(6);
    expect($sabado->startTime)->toBe('09:00');
    expect($sabado->endTime)->toBe('13:00');
});

test('"8am a 12pm" (sin espacio) sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 8am a 12pm', []);

    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('08:00');
    expect($result->value[0]->endTime)->toBe('12:00');
});

test('"8 am a 12 pm" (con espacio) sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 8 am a 12 pm', []);

    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('08:00');
    expect($result->value[0]->endTime)->toBe('12:00');
});

test('"12am a 6am" — medianoche literal (12am = 00:00), sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 12am a 6am', []);

    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('00:00');
    expect($result->value[0]->endTime)->toBe('06:00');
});

test('"12pm a 6pm" — mediodía literal (12pm = 12:00, no 24:00), sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 12pm a 6pm', []);

    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('12:00');
    expect($result->value[0]->endTime)->toBe('18:00');
});

test('"2:30pm a 5:45pm" — minutos explícitos combinados con sufijo, sin llamar a la IA', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes de 2:30pm a 5:45pm', []);

    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('14:30');
    expect($result->value[0]->endTime)->toBe('17:45');
});

test('caso ambiguo "8am a 12" (un solo lado con sufijo) NO se interpreta de forma determinista — cae a la IA', function () {
    $json = json_encode([
        ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
    ]);
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService($json));

    $result = $extractor->extract('Lunes de 8am a 12', []);

    // Si esto hubiera tocado el fake que lanza excepción, el test ya habría
    // fallado — acá se usa el fake que SÍ responde, confirmando que la IA
    // fue efectivamente invocada (no se adivinó el "12" suelto como 12pm).
    expect($result->successful)->toBeTrue();
    expect($result->value[0]->startTime)->toBe('08:00');
});

test('los formatos deterministas existentes (sin am/pm) siguen resolviéndose sin llamar a la IA tras la extensión', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleThrowingFakeService());

    $result = $extractor->extract('Lunes a Viernes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(5);
    expect($result->value[0]->startTime)->toBe('09:00');
    expect($result->value[0]->endTime)->toBe('17:00');
});

test('cae al fallback de IA si algún segmento de la lista no es reconocible', function () {
    $json = json_encode([
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
    ]);
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService($json));

    // El segundo segmento ("cuando yo pueda") no matchea la gramática
    // determinista — el mensaje entero debe caer a la IA, no devolver un
    // resultado parcial con solo el primer segmento resuelto.
    $result = $extractor->extract('Lunes de 9 a 17, cuando yo pueda', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(1);
});
