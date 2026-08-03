<?php

namespace App\Domain\Booking\ValueObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Value Object (Parte III) — inmutable, comparado por valor. Nace en el
 * Hito 2 porque recién ahora tiene un consumidor real de su comportamiento
 * (AvailabilityCalculator/BookingScheduler); en el Hito 1 hubiera sido
 * ceremonia sin uso.
 */
final class TimeRange
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(Carbon|CarbonImmutable $start, Carbon|CarbonImmutable $end)
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);

        if (! $end->greaterThan($start)) {
            throw new InvalidArgumentException('TimeRange inválido: end debe ser posterior a start.');
        }

        $this->start = $start;
        $this->end = $end;
    }

    public function durationInMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end) && $other->start->lessThan($this->end);
    }

    public function contains(self $other): bool
    {
        return ! $other->start->lessThan($this->start) && ! $other->end->greaterThan($this->end);
    }

    /**
     * Nueva TimeRange extendida hacia adelante por $minutes — usada para
     * modelar el buffer de un servicio como parte del período "ocupado".
     */
    public function withTrailingBuffer(int $minutes): self
    {
        return new self($this->start, $this->end->addMinutes($minutes));
    }

    public function equals(self $other): bool
    {
        return $this->start->equalTo($other->start) && $this->end->equalTo($other->end);
    }
}
