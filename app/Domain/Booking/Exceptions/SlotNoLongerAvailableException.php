<?php

namespace App\Domain\Booking\Exceptions;

use DomainException;

/**
 * Se lanza cuando BookingScheduler revalida disponibilidad bajo el lock del
 * recurso y descubre que ya no está libre (otra reserva ganó la carrera).
 * La capa de Application (Hito 3) la traduce a un mensaje para el cliente.
 */
class SlotNoLongerAvailableException extends DomainException {}
