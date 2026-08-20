<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use Carbon\CarbonImmutable;

/**
 * Único punto de entrada para que un canal reprograme una reserva existente
 * (Parte IX punto 3) — mismo motivo que CancelBookingCommand para no tener
 * un Data object propio.
 */
class RescheduleBookingCommand
{
    public function __construct(private readonly BookingSchedulerInterface $scheduler) {}

    public function handle(Booking $booking, CarbonImmutable $newStartsAt): RescheduleBookingResult
    {
        $previousStartsAt = CarbonImmutable::instance($booking->starts_at);
        $rescheduled = $this->scheduler->reschedule($booking, $newStartsAt);

        return RescheduleBookingResult::fromBooking($rescheduled, $previousStartsAt);
    }
}
