<?php

namespace App\Application\Tenancy;

use App\Domain\Tenancy\Channel;

/**
 * Alcance angosto a propósito (Parte XII/XVI): un servicio, un recurso,
 * un horario semanal simple. Generalizar a negocios multi-servicio/
 * multi-recurso es un incremento posterior, no este.
 */
final class RegisterOrganizationData
{
    /** @param WeeklyScheduleSlot[] $weeklySchedule */
    public function __construct(
        public readonly string $organizationName,
        public readonly string $ownerPhone,
        public readonly Channel $channel,
        public readonly ?string $city,
        public readonly ?string $address,
        public readonly string $serviceName,
        public readonly int $serviceDurationMinutes,
        public readonly string $resourceName,
        public readonly array $weeklySchedule,
    ) {}
}
