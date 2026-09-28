<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;

/**
 * Precio de un servicio — completamente opcional y determinista, sin IA
 * (mismo criterio que FreeTextFieldExtractor). Distingue tres casos reales
 * pedidos por el negocio:
 *
 *  1. Un valor numérico reconocible ("45000", "45.000", "$45.000",
 *     "$45,000") → Service::price.
 *  2. Una condición en lugar de un número ("depende de una valoración",
 *     "se define después de una consulta") → Service::price queda null,
 *     el texto tal cual (nunca inventado) se guarda en Service::description
 *     para que el negocio pueda comunicarlo a sus clientes.
 *  3. Un rechazo corto sin contenido informativo ("no") → los dos null, no
 *     se inventa ninguna condición adicional.
 *
 * Nunca falla — el precio nunca bloquea el registro del servicio.
 */
class ServicePriceFieldExtractor implements FieldExtractorInterface
{
    // Mismo vocabulario que NO_WORDS en los Agents de este dominio — un "no"
    // corto es un rechazo sin nada que registrar, no una condición.
    private const BARE_REFUSAL_WORDS = ['no', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna'];

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $trimmed = trim($answer);

        $numericPrice = $this->tryParseNumericPrice($trimmed);

        if ($numericPrice !== null) {
            return FieldExtractionResult::success(new ServicePriceResult($numericPrice, null));
        }

        $normalized = mb_strtolower(rtrim($trimmed, '.'));

        if ($trimmed === '' || in_array($normalized, self::BARE_REFUSAL_WORDS, true)) {
            return FieldExtractionResult::success(new ServicePriceResult(null, null));
        }

        // Cualquier otro texto no numérico es la propia explicación del
        // profesional — se guarda tal cual, nunca inventada ni parafraseada.
        return FieldExtractionResult::success(new ServicePriceResult(null, $trimmed));
    }

    /**
     * Reconoce ÚNICAMENTE un número (con "$" y separadores de miles "."/","
     * opcionales), anclado de punta a punta — "45000" matchea, pero "cuesta
     * 45000 el corte" o "45 mil" NO, a propósito: no hay que adivinar un
     * número dentro de una frase, y "45 mil" NO es un patrón numérico
     * reconocible según lo pedido explícitamente (no convertir expresiones
     * como esa de forma automática). Los separadores "."/"," se tratan
     * siempre como agrupadores de miles (no hay decimales en los casos
     * reales dados) — se descartan al limpiar, nunca se interpretan como
     * coma/punto decimal.
     */
    private function tryParseNumericPrice(string $text): ?float
    {
        if (! preg_match('/^\$?\s*(\d{1,3}(?:[.,]\d{3})+|\d+)$/u', $text)) {
            return null;
        }

        $digitsOnly = preg_replace('/[^\d]/', '', $text);

        if ($digitsOnly === '' || $digitsOnly === null) {
            return null;
        }

        return (float) $digitsOnly;
    }
}
