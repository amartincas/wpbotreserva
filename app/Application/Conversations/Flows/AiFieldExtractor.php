<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Contracts\AiServiceInterface;
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

        try {
            $response = trim($this->ai->getResponse($answer, $systemPrompt, []));
        } catch (Throwable) {
            return FieldExtractionResult::failure(
                "No pude procesar tu respuesta para {$this->fieldLabel}. ¿Podés intentarlo de nuevo?"
            );
        }

        if ($response === '' || strtoupper($response) === 'NO_ENCONTRADO') {
            return FieldExtractionResult::failure(
                "No entendí tu respuesta para {$this->fieldLabel}. ¿Podés ser más específico?"
            );
        }

        return FieldExtractionResult::success($response);
    }
}
