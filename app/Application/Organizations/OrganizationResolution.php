<?php

namespace App\Application\Organizations;

use App\Domain\Tenancy\Organization;

/**
 * Result DTO (mismo patrón que CreateBookingResult/RegisterOrganizationResult)
 * — nunca una excepción para un caso esperado como "hay que desambiguar".
 */
final class OrganizationResolution
{
    /**
     * @param  Organization[]  $candidates
     */
    private function __construct(
        public readonly OrganizationResolutionStatus $status,
        public readonly ?Organization $organization = null,
        public readonly array $candidates = [],
    ) {}

    public static function resolved(Organization $organization): self
    {
        return new self(OrganizationResolutionStatus::Resolved, organization: $organization);
    }

    /**
     * @param  Organization[]  $candidates
     */
    public static function pendingDisambiguation(array $candidates): self
    {
        return new self(OrganizationResolutionStatus::PendingDisambiguation, candidates: $candidates);
    }

    public static function unregistered(): self
    {
        return new self(OrganizationResolutionStatus::Unregistered);
    }
}
