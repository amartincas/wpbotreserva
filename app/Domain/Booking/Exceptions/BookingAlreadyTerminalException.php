<?php

namespace App\Domain\Booking\Exceptions;

use DomainException;

/**
 * Se lanza al intentar cancelar/reprogramar una reserva cuyo estado ya es
 * terminal (CANCELLED/COMPLETED/NO_SHOW) — Parte II R4: ningún estado
 * terminal tiene transiciones salientes.
 */
class BookingAlreadyTerminalException extends DomainException {}
