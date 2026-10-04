<?php

namespace App\Domain\Tenancy\Exceptions;

use DomainException;

/**
 * Invariante: como máximo un Channel CENTRAL ACTIVE a la vez — es el único
 * número por el que los owners administran y por el que salen las alertas
 * al owner, así que dos activos harían ambigua esa elección.
 */
class DuplicateCentralChannelException extends DomainException {}
