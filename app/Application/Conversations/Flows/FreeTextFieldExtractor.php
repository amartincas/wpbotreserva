<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;

/**
 * Campo de texto libre y opcional — a diferencia de AiFieldExtractor, acá
 * TODO el mensaje del usuario ES la respuesta, no hay nada que "extraer" de
 * una frase más larga (ej. descripción del negocio, descripción de un
 * servicio). Meter una llamada a IA para esto sería una dependencia y un
 * punto de falla innecesarios — mismo criterio de determinismo que ya sigue
 * el proyecto en WeeklyScheduleFieldExtractor/DateFieldExtractor, aplicado
 * acá porque ni siquiera hay un patrón que reconocer: cualquier texto no
 * vacío y que no sea una palabra de "omitir" es válido tal cual.
 *
 * Nunca falla — el campo es opcional, así que cualquier respuesta (incluida
 * una palabra de "omitir") produce éxito. Nunca bloquea el registro.
 */
class FreeTextFieldExtractor implements FieldExtractorInterface
{
    // Mismo vocabulario que NO_WORDS en RegistroNegocioAgent — un "no" corto
    // es la señal de "no quiero dar este dato", se guarda como null.
    private const SKIP_WORDS = ['no', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna', 'omitir', 'paso', 'skip'];

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $trimmed = trim($answer);

        if ($trimmed === '') {
            return FieldExtractionResult::success(null);
        }

        $normalized = mb_strtolower(rtrim($trimmed, '.'));

        if (in_array($normalized, self::SKIP_WORDS, true)) {
            return FieldExtractionResult::success(null);
        }

        return FieldExtractionResult::success($trimmed);
    }
}
