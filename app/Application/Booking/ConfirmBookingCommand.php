<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;

/**
 * Único punto de entrada para que un canal confirme una reserva PENDING
 * (comando admin `confirmar <id>`, Incremento 2) — mismo motivo que
 * CancelBookingCommand para no tener un Data object propio.
 */
class ConfirmBookingCommand
{
    public function __construct(private readonly BookingSchedulerInterface $scheduler) {}

    public function handle(Booking $booking): ConfirmBookingResult
    {
        $booking = $this->scheduler->confirm($booking);

        return ConfirmBookingResult::fromBooking($booking);
    }
}
