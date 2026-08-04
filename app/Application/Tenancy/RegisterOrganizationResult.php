<?php

namespace App\Application\Tenancy;

final class RegisterOrganizationResult
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly int $locationId,
        public readonly int $resourceId,
        public readonly int $serviceId,
    ) {}
}
