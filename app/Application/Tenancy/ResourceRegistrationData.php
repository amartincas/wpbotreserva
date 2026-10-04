<?php

namespace App\Application\Tenancy;

final class ResourceRegistrationData
{
    /** @param WeeklyScheduleSlot[] $weeklySchedule */
    public function __construct(
        public readonly string $name,
        public readonly array $weeklySchedule,
    ) {}
}
