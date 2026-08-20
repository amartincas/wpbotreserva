<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;

/**
 * Único punto de entrada para que un canal marque una reserva como
 * NO_SHOW (comando admin `ausente <id>`, Incremento 2) — mismo motivo que
 * CancelBookingCommand para no tener un Data object propio.
 */
class MarkBookingNoShowCommand
{
    public function __construct(private readonly BookingSchedulerInterface $scheduler) {}

    public function handle(Booking $booking): MarkBookingNoShowResult
    {
        $booking = $this->scheduler->markNoShow($booking);

        return MarkBookingNoShowResult::fromBooking($booking);
    }
}
