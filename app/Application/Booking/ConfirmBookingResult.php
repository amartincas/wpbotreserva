<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

final class ConfirmBookingResult
{
    public function __construct(
        public readonly int $bookingId,
        public readonly string $serviceName,
        public readonly CarbonImmutable $startsAt,
        public readonly BookingStatus $status,
    ) {}

    public static function fromBooking(Booking $booking): self
    {
        $booking->loadMissing('service');

        return new self(
            bookingId: $booking->id,
            serviceName: $booking->service->name,
            startsAt: CarbonImmutable::instance($booking->starts_at),
            status: $booking->status,
        );
    }
}
