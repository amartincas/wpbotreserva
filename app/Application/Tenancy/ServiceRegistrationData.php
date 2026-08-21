<?php

namespace App\Application\Tenancy;

final class ServiceRegistrationData
{
    public function __construct(
        public readonly string $name,
        public readonly int $durationMinutes,
    ) {}
}
