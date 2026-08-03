<?php

namespace App\Domain\Booking\Exceptions;

use DomainException;

/**
 * Entrada inválida para BookingScheduler que no es un problema de
 * concurrencia (ej. el recurso indicado no puede prestar el servicio).
 */
class InvalidBookingRequestException extends DomainException {}
