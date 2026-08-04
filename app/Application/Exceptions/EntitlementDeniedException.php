<?php

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * La organización alcanzó el límite de su plan para una capacidad dada
 * (Parte X). Con `UnlimitedEntitlementChecker` (MVP) nunca se lanza — el
 * punto de extensión ya existe para cuando Billing exista de verdad.
 */
class EntitlementDeniedException extends RuntimeException {}
