<?php

namespace App\Domain\Tenancy\Exceptions;

use DomainException;

/**
 * Invariante MVP: una Organization tiene como máximo un Channel BUSINESS
 * (su WhatsApp propio). Un segundo vínculo se rechaza en vez de dejar
 * ambiguo por qué número se le escribe a sus clientes.
 */
class BusinessChannelAlreadyConnectedException extends DomainException {}
