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
 */
class DateFieldExtractor implements FieldExtractorInterface
{
    public function __construct(private readonly AiServiceInterface $ai) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
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
