<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain Event (Parte III/IX), mismo criterio que BookingConfirmed — hecho
 * pasado, libre de cambiar de forma junto con el modelo interno del
 * contexto Booking.
 */
class BookingCancelled
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
