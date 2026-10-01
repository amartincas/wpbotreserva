<?php

namespace App\Application\Organizations;

use App\Domain\Tenancy\Organization;

/**
 * Result DTO (mismo patrón que CreateBookingResult/RegisterOrganizationResult).
 */
final class OrganizationResolution
{
    private function __construct(
        public readonly OrganizationResolutionStatus $status,
        public readonly ?Organization $organization = null,
    ) {}

    public static function resolved(Organization $organization): self
    {
        return new self(OrganizationResolutionStatus::Resolved, organization: $organization);
    }

    public static function unregistered(): self
    {
        return new self(OrganizationResolutionStatus::Unregistered);
    }
}
