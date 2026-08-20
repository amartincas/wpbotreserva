<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Contracts\AiServiceInterface;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Extractor especializado (igual que WeeklyScheduleFieldExtractor) — una
 * fecha en lenguaje libre ("mañana", "el viernes que viene") necesita saber
 * la fecha de hoy para resolverse, algo que un AiFieldExtractor genérico no
 * contempla. Implementa el mismo FieldExtractorInterface.
 */
class DateFieldExtractor implements FieldExtractorInterface
{
    public function __construct(private readonly AiServiceInterface $ai) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $today = now()->toDateString();

        $systemPrompt = <<<PROMPT
            Hoy es {$today}. Extraé EXCLUSIVAMENTE la fecha a la que se refiere el mensaje del usuario (el día que quiere el turno).
            Si el mensaje es solo un número de 1 a 31 (por ejemplo "24" o "el 24"), interpretalo como ese día del mes actual — o del próximo mes si ese día ya pasó este mes.
            Respondé con la fecha en formato YYYY-MM-DD, sin texto adicional.
            Si el mensaje no contiene una referencia de fecha clara, respondé exactamente: NO_ENCONTRADO
            PROMPT;

        try {
            $response = trim($this->ai->getResponse($answer, $systemPrompt, []));
        } catch (Throwable) {
            return $this->failure();
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
}
