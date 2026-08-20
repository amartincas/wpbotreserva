<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain Event (Parte III/IX). Lleva $previousStartsAt además del booking ya
 * actualizado (que solo tiene el horario nuevo) porque el listener de
 * notificación necesita poder decir "se movió de X a Y" — ese dato no
 * sobrevive en ningún otro lado una vez que el UPDATE ya se aplicó.
 */
class BookingRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
    ) {}
}
