<?php

namespace App\Application\Contracts;

use App\Domain\Tenancy\Organization;

/**
 * Contrato basado en capacidades, nunca en nombres de plan (Parte X) — el
 * resto del sistema pregunta "¿puede hacer esta acción?", jamás "¿qué plan
 * tiene?". $entitlementKey namespaced por el contexto que la consume (ej.
 * "scheduling.max_locations") para evitar colisiones cuando el catálogo de
 * capacidades crezca. $requestedQuantity es lo que el caller tendría
 * DESPUÉS de la acción — para flags booleanas simplemente se ignora.
 */
interface EntitlementCheckerInterface
{
    public function check(Organization $organization, string $entitlementKey, int $requestedQuantity = 1): bool;
}
