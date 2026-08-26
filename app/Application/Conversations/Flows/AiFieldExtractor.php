<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementación genérica reutilizable entre campos simples (texto, número)
 * de cualquier flujo — Registro hoy, Reservas mañana. Un campo
 * estructurado (ej. horario semanal) puede necesitar su propio extractor
 * concreto implementando la misma interfaz, sin romper el contrato.
 *
 * El prompt pide explícitamente UN solo dato y nada más (decisión
 * documentada en FieldExtractorInterface) — nunca "extraé todo lo que
 * encuentres".
 */
class AiFieldExtractor implements FieldExtractorInterface
{
    public function __construct(
        private readonly AiServiceInterface $ai,
        private readonly string $fieldLabel,
        private readonly string $fieldDescription,
    ) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $systemPrompt = <<<PROMPT
            Extraé EXCLUSIVAMENTE el siguiente dato del mensaje del usuario: {$this->fieldLabel}.
            {$this->fieldDescription}
            Ignorá cualquier otra información presente en el mensaje, aunque parezca relevante para otro campo — no es tu trabajo interpretarla acá.
            Si el mensaje no contiene ese dato de forma clara, respondé exactamente: NO_ENCONTRADO
            Respondé solo con el valor extraído, sin texto adicional, sin comillas ni explicación.
            PROMPT;

        // Caso real: una llamada lenta o fallida a la IA a mitad de un flujo
        // dejó una sesión en un estado inconsistente (Incremento 4), y sin
        // ningún log no hubo forma de reconstruir qué pasó después — solo
        // se pudo reproducir a mano. duration_ms queda siempre, éxito o no,
        // para poder distinguir "falló" de "tardó demasiado".
        $startedAt = microtime(true);

        try {
            $response = trim($this->ai->getResponse($answer, $systemPrompt, []));
        } catch (Throwable $e) {
            Log::warning('AiFieldExtractor: la llamada a la IA falló', [
                'field' => $this->fieldLabel,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            return FieldExtractionResult::failure(
                "No pude procesar tu respuesta para {$this->fieldLabel}. ¿Podés intentarlo de nuevo?"
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($durationMs > 5000) {
            Log::warning('AiFieldExtractor: la llamada a la IA tardó más de lo esperado', [
                'field' => $this->fieldLabel,
                'duration_ms' => $durationMs,
            ]);
        }

        if ($response === '' || strtoupper($response) === 'NO_ENCONTRADO') {
            return FieldExtractionResult::failure(
                "No entendí tu respuesta para {$this->fieldLabel}. ¿Podés ser más específico?"
            );
        }

        return FieldExtractionResult::success($response);
    }
}
