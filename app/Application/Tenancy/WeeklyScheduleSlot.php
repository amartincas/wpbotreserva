<?php

namespace App\Application\Tenancy;

final class WeeklyScheduleSlot
{
    public function __construct(
        public readonly int $weekday,
        public readonly string $startTime,
        public readonly string $endTime,
    ) {}
}
