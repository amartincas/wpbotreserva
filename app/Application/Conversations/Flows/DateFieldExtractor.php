<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Contracts\AiServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extractor especializado (igual que WeeklyScheduleFieldExtractor) — una
 * fecha en lenguaje libre ("mañana", "el viernes que viene") necesita saber
 * la fecha de hoy para resolverse, algo que un AiFieldExtractor genérico no
 * contempla. Implementa el mismo FieldExtractorInterface.
 *
 * Bug real encontrado en producción: pedirle a la IA por prompt que "nunca
 * adivine el mes" cuando el usuario da solo un número de día ("24", "el
 * 24") no fue confiable — la misma frase, en corridas distintas, resolvía
 * a veces al mes actual y a veces al siguiente. Para una decisión de este
 * tipo (¿qué fecha reservo?) no alcanza con instruirle a la IA que no
 * adivine — hay que impedirlo en código, de forma determinista, ANTES de
 * siquiera llamarla. isAmbiguousDayWithoutMonth() hace exactamente eso.
 *
 * Segunda ronda, mismo motivo (caso real): "lunes" a veces no resolvía a
 * ningún día, "lunes 31" caía en el chequeo de mes ambiguo de arriba (un
 * día de semana + un número de día NO es ambiguo — hay una sola fecha
 * próxima donde coinciden ambos) y "08-31-2026" (mes-día-año, formato que
 * algunos usuarios tipean por costumbre) confundía a la IA. Para estos tres
 * casos concretos — nombre de día de la semana, día de la semana + número
 * de día, y fecha numérica con separador — la resolución es 100%
 * determinista en código (tryDeterministicParse), ni siquiera se llama a la
 * IA. Todo lo demás (referencias sueltas como "mañana", "el viernes que
 * viene", "en 3 semanas") sigue yendo a la IA sin cambios — ahí sí funciona
 * bien y no hace falta reemplazarlo.
 */
class DateFieldExtractor implements FieldExtractorInterface
{
    // Mismo convenio que ResourceSchedule.weekday / WeeklyScheduleFieldExtractor
    // (0=domingo..6=sábado) — y el mismo que espera CarbonImmutable::next().
    private const WEEKDAY_NAMES = [
        'domingo' => 0,
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'miércoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
        'sábado' => 6,
    ];

    public function __construct(private readonly AiServiceInterface $ai) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $deterministic = $this->tryDeterministicParse($answer);

        if ($deterministic !== null) {
            return $deterministic;
        }

        if ($this->isAmbiguousDayWithoutMonth($answer)) {
            return FieldExtractionResult::failure('¿De qué mes? Por ejemplo: "24 de agosto".');
        }

        $today = now()->toDateString();

        $systemPrompt = <<<PROMPT
            Hoy es {$today}. Extraé EXCLUSIVAMENTE la fecha a la que se refiere el mensaje del usuario (el día que quiere el turno).
            Respondé con la fecha en formato YYYY-MM-DD, sin texto adicional.
            Si el mensaje no contiene una referencia de fecha clara, respondé exactamente: NO_ENCONTRADO
            PROMPT;

        // Mismo motivo que AiFieldExtractor: sin esto, una llamada lenta o
        // fallida acá es indistinguible de "la IA no entendió la fecha".
        $startedAt = microtime(true);

        try {
            $response = trim($this->ai->getResponse($answer, $systemPrompt, []));
        } catch (Throwable $e) {
            Log::warning('DateFieldExtractor: la llamada a la IA falló', [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            return $this->failure();
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($durationMs > 5000) {
            Log::warning('DateFieldExtractor: la llamada a la IA tardó más de lo esperado', [
                'duration_ms' => $durationMs,
            ]);
        }

        if ($response === '' || strtoupper($response) === 'NO_ENCONTRADO') {
            return $this->failure();
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $response)->startOfDay();
        } catch (Throwable) {
            return $this->failure();
        }

        if ($date->isBefore(now()->startOfDay())) {
            return FieldExtractionResult::failure('Esa fecha ya pasó. ¿Para qué día querés el turno?');
        }

        return FieldExtractionResult::success($date);
    }

    private function failure(): FieldExtractionResult
    {
        return FieldExtractionResult::failure('No entendí la fecha. ¿Podés decirme para qué día querés el turno?');
    }

    /**
     * null = "acá no hay un patrón determinista reconocido, seguí con el
     * flujo de siempre" (chequeo de ambigüedad + IA) — nunca un fallo en sí
     * mismo. Un FieldExtractionResult no-null (éxito o fallo) corta el flujo
     * acá, sin tocar el chequeo de ambigüedad ni llamar a la IA.
     */
    private function tryDeterministicParse(string $text): ?FieldExtractionResult
    {
        $normalized = mb_strtolower(trim($text));

        $weekday = $this->matchWeekday($normalized);
        $dayOfMonth = $this->matchBareDayNumber($normalized);

        if ($weekday !== null && $dayOfMonth !== null) {
            return $this->resolveWeekdayWithDayOfMonth($weekday, $dayOfMonth);
        }

        if ($weekday !== null) {
            return $this->resolveNextWeekday($weekday);
        }

        $numericDate = $this->matchNumericDate($normalized);

        if ($numericDate !== null) {
            return $this->buildDateResult($numericDate);
        }

        return null;
    }

    private function matchWeekday(string $normalized): ?int
    {
        foreach (self::WEEKDAY_NAMES as $name => $isoWeekday) {
            if (preg_match('/\b'.$name.'\b/u', $normalized)) {
                return $isoWeekday;
            }
        }

        return null;
    }

    /**
     * Mismo criterio que isAmbiguousDayWithoutMonth() para no confundir un
     * día del mes con una cantidad ("en 5 años", "para 2 personas").
     */
    private function matchBareDayNumber(string $normalized): ?int
    {
        if (preg_match(
            '/\b([1-9]|[12]\d|3[01])\b(?!\s*(años?|días?|semanas?|meses?|personas?|veces|horas?|minutos?))/u',
            $normalized,
            $matches
        )) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * "lunes" (sin número) — se entiende como el próximo lunes que viene,
     * nunca hoy aunque hoy sea lunes (mismo criterio natural que usaría una
     * persona agendando un turno).
     */
    private function resolveNextWeekday(int $isoWeekday): FieldExtractionResult
    {
        return FieldExtractionResult::success(
            CarbonImmutable::now()->startOfDay()->next($isoWeekday)
        );
    }

    /**
     * "lunes 31" — no es ambiguo aunque no diga el mes: hay una sola fecha,
     * a partir de hoy, donde ese día de la semana y ese número de día
     * coinciden. Se busca hacia adelante (día por día, hasta 2 años) en vez
     * de preguntar el mes — evita el falso positivo de
     * isAmbiguousDayWithoutMonth() para este caso puntual.
     */
    private function resolveWeekdayWithDayOfMonth(int $isoWeekday, int $dayOfMonth): FieldExtractionResult
    {
        $cursor = CarbonImmutable::now()->startOfDay();
        $limit = $cursor->addYears(2);

        while ($cursor->lessThanOrEqualTo($limit)) {
            if ($cursor->day === $dayOfMonth && $cursor->dayOfWeek === $isoWeekday) {
                return FieldExtractionResult::success($cursor);
            }

            $cursor = $cursor->addDay();
        }

        // No debería pasar en la práctica (día 31 + cualquier día de semana
        // aparece dentro del año), pero si el número de día no es un día de
        // calendario real (32+, ya filtrado arriba) o algo raro impide el
        // match, se cae al flujo normal de ambigüedad en vez de romper.
        return $this->failure();
    }

    /**
     * Fecha numérica con separador ("31-08-2026", "08-31-2026", "8/5/26").
     * Bug real: la IA a veces interpretaba mal el orden día/mes, sobre todo
     * cuando alguien tipeaba mes-día-año por costumbre (formato de EE.UU.).
     * Regla determinista: si uno de los dos primeros números es mayor a 12,
     * ESE tiene que ser el día (ningún mes pasa de 12) — sin eso, ambos
     * podrían ser el mes y no hay forma de saber cuál es cuál. Si los dos
     * son ambiguos (ambos <=12), se asume día-mes-año — la convención de
     * este proyecto/Colombia, nunca mes-día-año.
     */
    private function matchNumericDate(string $normalized): ?CarbonImmutable
    {
        if (! preg_match('/\b(\d{1,2})\s*[\/\-]\s*(\d{1,2})\s*[\/\-]\s*(\d{2,4})\b/u', $normalized, $matches)) {
            return null;
        }

        $a = (int) $matches[1];
        $b = (int) $matches[2];
        $year = (int) $matches[3];

        if ($year < 100) {
            $year += 2000;
        }

        if ($a > 12 && $b > 12) {
            // Ninguno de los dos puede ser un mes válido — no es una fecha.
            return null;
        }

        [$day, $month] = $b > 12 ? [$b, $a] : ($a > 12 ? [$a, $b] : [$a, $b]);

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
    }

    private function buildDateResult(CarbonImmutable $date): FieldExtractionResult
    {
        if ($date->isBefore(CarbonImmutable::now()->startOfDay())) {
            return FieldExtractionResult::failure('Esa fecha ya pasó. ¿Para qué día querés el turno?');
        }

        return FieldExtractionResult::success($date);
    }

    /**
     * true si el mensaje trae un número de 1 a 31 (posible día del mes)
     * pero ningún mes explícito (ni en palabra, ni como "dd/mm" o "dd-mm")
     * que lo desambigüe. No se aplica si ya hay un mes indicado, ni a
     * referencias sin número (esas no las ambigua el mes: "mañana", "el
     * domingo", "el viernes que viene" resuelven bien con la IA sola).
     *
     * El número NO cuenta como posible día si va seguido de una unidad que
     * indica que es una cantidad, no una fecha ("en 5 años", "en 3 días",
     * "2 personas") — sin esto, "en 5 años" (usado deliberadamente en
     * tests para forzar una fecha lejana) quedaba marcado como ambiguo por
     * error, cuando en realidad no menciona ningún día del mes.
     */
    private function isAmbiguousDayWithoutMonth(string $text): bool
    {
        $normalized = mb_strtolower($text);

        $hasMonthName = (bool) preg_match(
            '/\b(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\b/u',
            $normalized
        );
        $hasNumericMonth = (bool) preg_match('/\b\d{1,2}\s*[\/\-]\s*\d{1,2}\b/u', $normalized);

        if ($hasMonthName || $hasNumericMonth) {
            return false;
        }

        return (bool) preg_match(
            '/\b([1-9]|[12]\d|3[01])\b(?!\s*(años?|días?|semanas?|meses?|personas?|veces|horas?|minutos?))/u',
            $normalized
        );
    }
}
