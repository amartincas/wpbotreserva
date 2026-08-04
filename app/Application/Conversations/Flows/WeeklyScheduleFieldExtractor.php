<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
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
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Extraé EXCLUSIVAMENTE el horario semanal de atención del mensaje del usuario.
        Días de la semana: 1=Lunes, 2=Martes, 3=Miércoles, 4=Jueves, 5=Viernes, 6=Sábado, 7=Domingo.
        Respondé con un array JSON de objetos, uno por cada día mencionado (ej. "Lunes a Viernes" son 5 objetos), con esta forma exacta:
        [{"weekday": 1, "start_time": "09:00", "end_time": "17:00"}]
        Los horarios van en formato HH:MM de 24 horas.
        Si el mensaje no contiene un horario claro, respondé exactamente: NO_ENCONTRADO
        Respondé solo el JSON (o NO_ENCONTRADO), sin texto adicional ni explicación.
        PROMPT;

    public function __construct(private readonly AiServiceInterface $ai) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        try {
            $response = trim($this->ai->getResponse($answer, self::SYSTEM_PROMPT, []));
        } catch (Throwable) {
            return $this->failure();
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
            'No entendí el horario. ¿Podés escribirlo de nuevo? (ej: "Lunes a Viernes de 9 a 17")'
        );
    }
}
