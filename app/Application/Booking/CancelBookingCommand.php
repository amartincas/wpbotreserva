<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;

/**
 * Único punto de entrada para que un canal cancele una reserva existente —
 * nunca invoca BookingScheduler directamente desde un Agente (Parte IX
 * punto 3). A diferencia de CreateBookingCommand, no necesita un Data
 * object propio: el único insumo real ya es un aggregate resuelto
 * (`Booking`) más un string opcional, sin nada que orquestar antes de
 * llamar al dominio.
 */
class CancelBookingCommand
{
    public function __construct(private readonly BookingSchedulerInterface $scheduler) {}

    public function handle(Booking $booking, ?string $reason = null): CancelBookingResult
    {
        $booking = $this->scheduler->cancel($booking, $reason);

        return CancelBookingResult::fromBooking($booking);
    }
}
