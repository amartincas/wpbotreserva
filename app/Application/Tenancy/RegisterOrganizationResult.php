<?php

namespace App\Application\Tenancy;

final class RegisterOrganizationResult
{
    /**
     * @param  int[]  $resourceIds
     * @param  int[]  $serviceIds
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly int $locationId,
        public readonly array $resourceIds,
        public readonly array $serviceIds,
    ) {}
}
