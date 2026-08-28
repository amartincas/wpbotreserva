<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Extractor especializado (a diferencia de AiFieldExtractor, no genérico) —
 * el horario semanal es un campo estructurado, multi-valor, que necesita su
 * propia lógica de interpretación. Implementa el mismo FieldExtractorInterface,
 * sin romper el contrato ni tocar ConversationalFlowRunner.
 */
class WeeklyScheduleFieldExtractor implements FieldExtractorInterface
{
    // Convención 0=domingo..6=sábado — la misma que resource_schedules.weekday
    // (documentada en su migración, Hito 1) y la que consume AvailabilityCalculator
    // vía Carbon::dayOfWeek. Bug real encontrado en el Hito 8: esta clase
    // originalmente le pedía a la IA 1=lunes..7=domingo (ISO) — lunes a
    // sábado coinciden numéricamente con ambas convenciones por pura
    // casualidad, así que quedó invisible hasta que un domingo real
    // (weekday=7 guardado, weekday=0 consultado) devolvió disponibilidad
    // vacía sin ningún error visible.
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Extraé EXCLUSIVAMENTE el horario semanal de atención del mensaje del usuario.
        Días de la semana: 0=Domingo, 1=Lunes, 2=Martes, 3=Miércoles, 4=Jueves, 5=Viernes, 6=Sábado.
        Respondé con un array JSON de objetos, uno por cada día mencionado (ej. "Lunes a Viernes" son 5 objetos), con esta forma exacta:
        [{"weekday": 1, "start_time": "09:00", "end_time": "17:00"}]
        Si un día tiene más de un rango horario (ej. "jueves de 8 a 12 y de 2 a 6"), devolvé un objeto JSON por cada rango, repitiendo el mismo "weekday".
        Los horarios van en formato HH:MM de 24 horas.
        Si el mensaje no contiene un horario claro, respondé exactamente: NO_ENCONTRADO
        Respondé solo el JSON (o NO_ENCONTRADO), sin texto adicional ni explicación.
        PROMPT;

    public function __construct(
        private readonly AiServiceInterface $ai,
        private readonly ?BotMessageRepository $botMessages = null,
    ) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $deterministic = $this->tryDeterministicParse($answer);

        if ($deterministic !== null) {
            return $deterministic;
        }

        // Mismo motivo que AiFieldExtractor: sin esto, una llamada lenta o
        // fallida acá es indistinguible de "la IA no entendió el horario".
        $startedAt = microtime(true);

        try {
            $response = trim($this->ai->getResponse($answer, self::SYSTEM_PROMPT, []));
        } catch (Throwable $e) {
            Log::warning('WeeklyScheduleFieldExtractor: la llamada a la IA falló', [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            return $this->failure();
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($durationMs > 5000) {
            Log::warning('WeeklyScheduleFieldExtractor: la llamada a la IA tardó más de lo esperado', [
                'duration_ms' => $durationMs,
            ]);
        }

        if ($response === '' || strtoupper($response) === 'NO_ENCONTRADO') {
            return $this->failure();
        }

        try {
            $decoded = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure();
        }

        if (! is_array($decoded) || $decoded === []) {
            return $this->failure();
        }

        try {
            $slots = array_map(
                fn (array $slot) => new WeeklyScheduleSlot(
                    weekday: (int) $slot['weekday'],
                    startTime: $slot['start_time'],
                    endTime: $slot['end_time'],
                ),
                $decoded
            );
        } catch (Throwable) {
            return $this->failure();
        }

        return FieldExtractionResult::success($slots);
    }

    private function failure(): FieldExtractionResult
    {
        return FieldExtractionResult::failure(
            $this->botMessages?->render('horario.no_entendido')
                ?? 'No entendí el horario. ¿Podés escribirlo de nuevo? (ej: "Lunes a Viernes de 9 a 17")'
        );
    }

    /**
     * null = "acá no hay un patrón determinista reconocido, seguí con la
     * IA" (nunca un fallo en sí mismo, mismo contrato que
     * DateFieldExtractor::tryDeterministicParse). Un resultado no-null corta
     * el flujo acá, sin llamar a la IA.
     *
     * Reconoce listas separadas por coma de bloques "día(s) de hora(s)", ej.
     * "Martes de 2 a 6, miércoles de 8 a 12, jueves de 8 a 12 y de 2 a 6,
     * viernes de 8 a 2" — el caso real que motivó este parser: antes, si el
     * primer día mencionado no era "Lunes", la IA lo interpretaba de forma
     * no reproducible (a veces bien, a veces mal) según la corrida. Si
     * CUALQUIER bloque de la lista no matchea la gramática reconocida acá,
     * se descarta el mensaje entero y se cae al fallback de IA — nunca un
     * resultado parcial.
     */
    private function tryDeterministicParse(string $text): ?FieldExtractionResult
    {
        $segments = array_map('trim', explode(',', mb_strtolower(trim($text))));
        $slots = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $parsed = $this->parseSegment($segment);

            if ($parsed === null) {
                return null;
            }

            array_push($slots, ...$parsed);
        }

        if ($slots === []) {
            return null;
        }

        return FieldExtractionResult::success($slots);
    }

    /**
     * Un bloque es "{días} de {horas}" — ej. "jueves de 8 a 12 y de 2 a 6".
     * Se separa por la PRIMERA ocurrencia de " de " (los siguientes " de "
     * posibles, dentro de {horas}, son el conector entre franjas horarias).
     *
     * @return WeeklyScheduleSlot[]|null
     */
    private function parseSegment(string $segment): ?array
    {
        $parts = explode(' de ', $segment, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$daysPart, $hoursPart] = $parts;

        $weekdays = $this->parseDays(trim($daysPart));

        if ($weekdays === null) {
            return null;
        }

        $hourRanges = $this->parseHourRanges(trim($hoursPart));

        if ($hourRanges === null) {
            return null;
        }

        $slots = [];

        foreach ($weekdays as $weekday) {
            foreach ($hourRanges as [$startTime, $endTime]) {
                $slots[] = new WeeklyScheduleSlot($weekday, $startTime, $endTime);
            }
        }

        return $slots;
    }

    /**
     * "lunes a viernes" (rango) o "lunes y martes" / "jueves" (lista de uno
     * o más días).
     *
     * @return int[]|null
     */
    private function parseDays(string $daysPart): ?array
    {
        if (str_contains($daysPart, ' a ')) {
            [$fromName, $toName] = array_map('trim', explode(' a ', $daysPart, 2));
            $fromIndex = SpanishWeekdayNames::indexOf($fromName);
            $toIndex = SpanishWeekdayNames::indexOf($toName);

            if ($fromIndex === null || $toIndex === null) {
                return null;
            }

            return $this->expandWeekdayRange($fromIndex, $toIndex);
        }

        $weekdays = [];

        foreach (explode(' y ', $daysPart) as $name) {
            $index = SpanishWeekdayNames::indexOf(trim($name));

            if ($index === null) {
                return null;
            }

            $weekdays[] = $index;
        }

        return $weekdays;
    }

    /**
     * Expande un rango de días recorriendo el orden circular lunes..domingo
     * (SpanishWeekdayNames::WEEK_ORDER), para cubrir tanto "Lunes a Viernes"
     * como el caso menos común que cruza el corte "Viernes a Domingo".
     *
     * @return int[]
     */
    private function expandWeekdayRange(int $fromIndex, int $toIndex): array
    {
        $order = SpanishWeekdayNames::WEEK_ORDER;
        $fromPos = array_search($fromIndex, $order, true);
        $toPos = array_search($toIndex, $order, true);

        if ($fromPos <= $toPos) {
            return array_slice($order, $fromPos, $toPos - $fromPos + 1);
        }

        return [
            ...array_slice($order, $fromPos),
            ...array_slice($order, 0, $toPos + 1),
        ];
    }

    /**
     * "8 a 12 y de 2 a 6" → dos franjas para el mismo día. El conector entre
     * franjas es " y de " (repite la preposición), distinto del " y " que
     * separa nombres de día en parseDays().
     *
     * @return array<int, array{0: string, 1: string}>|null
     */
    private function parseHourRanges(string $hoursPart): ?array
    {
        $ranges = [];

        foreach (explode(' y de ', $hoursPart) as $token) {
            $range = $this->parseSingleHourRange(trim($token));

            if ($range === null) {
                return null;
            }

            $ranges[] = $range;
        }

        return $ranges;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseSingleHourRange(string $token): ?array
    {
        if (! preg_match('/^(\d{1,2})(?::([0-5]\d))?\s*a\s*(\d{1,2})(?::([0-5]\d))?$/u', $token, $matches)) {
            return null;
        }

        $startHour = (int) $matches[1];
        $startMinute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $endHour = (int) $matches[3];
        $endMinute = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;

        if ($startHour > 23 || $endHour > 23) {
            return null;
        }

        $resolved = $this->resolveHourPair($startHour, $endHour);

        if ($resolved === null) {
            return null;
        }

        [$resolvedStart, $resolvedEnd] = $resolved;

        return [
            sprintf('%02d:%02d', $resolvedStart, $startMinute),
            sprintf('%02d:%02d', $resolvedEnd, $endMinute),
        ];
    }

    /**
     * Heurística AM/PM para horas escritas sin sufijo ("de 2 a 6"). Casos
     * reales que motivaron cada rama:
     *  - Alguna de las dos horas es >12 (ej. "9 a 17"): ya es formato 24h
     *    explícito, se usa tal cual.
     *  - Ambas ≤12 y el inicio es <8 (ej. "2 a 6"): ningún negocio abre
     *    antes de las 8, así que el rango entero es de la tarde/noche
     *    (+12 a ambos extremos) → 14 a 18.
     *  - Ambas ≤12, inicio ≥8, pero el fin es numéricamente menor o igual al
     *    inicio (ej. "8 a 2"): el inicio es de la mañana tal cual, y el fin,
     *    al ser menor, es de la tarde (+12) → 8 a 14. Este dominio nunca
     *    cruza la medianoche.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolveHourPair(int $startHour, int $endHour): ?array
    {
        if ($startHour > 12 || $endHour > 12) {
            return $endHour > $startHour ? [$startHour, $endHour] : null;
        }

        if ($startHour < 8) {
            $startHour += 12;
            $endHour += 12;
        } elseif ($endHour <= $startHour) {
            $endHour += 12;
        }

        return $endHour > $startHour ? [$startHour, $endHour] : null;
    }
}
