<?php

namespace App\Application\Tenancy;

final class ServiceRegistrationData
{
    /**
     * $resourceKeys es específico de RegisterOrganizationCommand: índices
     * dentro de RegisterOrganizationData::$resources, resueltos a ids reales
     * al persistir. AddServiceCommand reusa este mismo DTO para nombre y
     * duración pero recibe los ids de recurso por su propio parámetro
     * explícito — ahí $resourceKeys queda sin usar (default []).
     *
     * @param  int[]  $resourceKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly int $durationMinutes,
        public readonly array $resourceKeys = [],
    ) {}
}
