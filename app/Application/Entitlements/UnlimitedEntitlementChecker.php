<?php

namespace App\Application\Entitlements;

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Domain\Tenancy\Organization;

/**
 * Implementación trivial del MVP (Parte X) — siempre permite. Se reemplaza
 * por una respaldada en Subscription/Plan cuando Billing exista, sin tocar
 * a quien la consume.
 */
class UnlimitedEntitlementChecker implements EntitlementCheckerInterface
{
    public function check(Organization $organization, string $entitlementKey, int $requestedQuantity = 1): bool
    {
        return true;
    }
}
