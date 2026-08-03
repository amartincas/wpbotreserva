<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain Event (Parte III/IX) — hecho pasado, libre de cambiar de forma
 * junto con el modelo interno del contexto Booking. Todavía sin listener
 * (la notificación por WhatsApp vía NotificationSender es Hito 3) — el
 * dominio no sabe ni le importa quién escucha.
 */
class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
