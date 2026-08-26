<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
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
        Los horarios van en formato HH:MM de 24 horas.
        Si el mensaje no contiene un horario claro, respondé exactamente: NO_ENCONTRADO
        Respondé solo el JSON (o NO_ENCONTRADO), sin texto adicional ni explicación.
        PROMPT;

    public function __construct(private readonly AiServiceInterface $ai) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
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
            'No entendí el horario. ¿Podés escribirlo de nuevo? (ej: "Lunes a Viernes de 9 a 17")'
        );
    }
}
