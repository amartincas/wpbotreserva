<?php

namespace App\Domain\Tenancy\Exceptions;

use DomainException;

/**
 * Invariante de la separación CENTRAL / BUSINESS: el Channel CENTRAL es de
 * WpbotReserva, nunca de una Organization — no puede aparecer en
 * channel_organization, ni un Channel ya vinculado puede pasar a CENTRAL
 * sin desvincularse antes (ver channels:promote-central).
 */
class CentralChannelLinkException extends DomainException {}
