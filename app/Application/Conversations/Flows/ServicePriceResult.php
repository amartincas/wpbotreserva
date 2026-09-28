<?php

namespace App\Application\Conversations\Flows;

/**
 * Resultado de ServicePriceFieldExtractor — nunca los dos a la vez:
 * - $price no-null: se reconoció un valor numérico explícito ("$45.000").
 * - $priceCondition no-null: el profesional explicó por qué no hay un
 *   precio fijo ("depende de una valoración") — texto tal cual, nunca
 *   parafraseado ni inventado, para guardar en Service::description.
 * - Los dos null: rechazo corto sin contenido informativo ("no").
 */
final class ServicePriceResult
{
    public function __construct(
        public readonly ?float $price,
        public readonly ?string $priceCondition,
    ) {}
}
