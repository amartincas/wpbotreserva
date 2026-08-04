<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

/**
 * Resultado plano (Parte IX punto 3) — quien invoca el Command (el Agente
 * Reservas, Hito 6) nunca recibe el aggregate `Booking` de Eloquent
 * directamente, solo estos datos ya resueltos.
 */
final class CreateBookingResult
{
    public function __construct(
        public readonly int $bookingId,
        public readonly string $serviceName,
        public readonly ?string $resourceName,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly BookingStatus $status,
    ) {}

    public static function fromBooking(Booking $booking): self
    {
        $booking->loadMissing(['service', 'bookingResources.resource']);

        return new self(
            bookingId: $booking->id,
            serviceName: $booking->service->name,
            resourceName: $booking->bookingResources->first()?->resource?->display_name,
            startsAt: CarbonImmutable::instance($booking->starts_at),
            endsAt: CarbonImmutable::instance($booking->ends_at),
            status: $booking->status,
        );
    }
}
