<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;

/**
 * Duración de un servicio — determinista para un entero desnudo
 * inequívoco ("45", "45 minutos", "45min", "45min."), sin tocar la IA.
 * A diferencia de ServicePriceFieldExtractor (precio, opcional, nunca
 * falla), duración es un campo OBLIGATORIO: cuando el patrón determinista
 * no matchea, no hay "éxito con NULL" posible — se delega íntegro al
 * AiFieldExtractor ya configurado (mismo prompt, mismo comportamiento de
 * hoy, compuesto por inyección, nunca modificado).
 *
 * Caso real que motivó esto (E2E post-Fase 1): una respuesta "45" bien
 * formada fue rechazada por la IA una vez y aceptada la siguiente, con el
 * mismo texto exacto — no-determinismo de la IA para un caso que no tiene
 * ninguna ambigüedad real y no debería depender de que la IA "adivine
 * bien" en cada corrida.
 */
class DurationFieldExtractor implements FieldExtractorInterface
{
    private const MIN_MINUTES = 1;

    private const MAX_MINUTES = 999;

    public function __construct(private readonly AiFieldExtractor $aiExtractor) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $minutes = $this->tryParseBareMinutes($answer);

        if ($minutes !== null) {
            return FieldExtractionResult::success((string) $minutes);
        }

        return $this->aiExtractor->extract($answer, $draftSoFar);
    }

    /**
     * Reconoce ÚNICAMENTE un entero de 1 a 3 dígitos, opcionalmente
     * seguido de "min"/"minutos" (con o sin espacio, con o sin punto
     * final) — anclado de punta a punta, mismo criterio que
     * ServicePriceFieldExtractor::tryParseNumericPrice(): nunca adivinar
     * un número dentro de una frase más larga. Fuera del rango 1-999
     * (incluido 0) cae al fallback de IA, igual que cualquier otro texto
     * no reconocido — nunca se trunca ni se inventa un valor.
     */
    private function tryParseBareMinutes(string $text): ?int
    {
        if (! preg_match('/^(\d{1,3})\s*(?:min(?:utos)?\.?)?$/ui', trim($text), $matches)) {
            return null;
        }

        $minutes = (int) $matches[1];

        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            return null;
        }

        return $minutes;
    }
}
