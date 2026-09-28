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
     * $description y $price son opcionales (Fase 1 de información general
     * del negocio) — nunca bloquean el alta del servicio. Cuando el
     * profesional explica una condición de precio en vez de dar un número
     * ("depende de una valoración"), esa condición YA viene combinada acá
     * dentro de $description por quien arma este DTO (ver
     * RegistroNegocioAgent/GestionNegocioAgent) — Service solo tiene una
     * columna de texto libre, no una separada para "condición de precio".
     *
     * @param  int[]  $resourceKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly int $durationMinutes,
        public readonly array $resourceKeys = [],
        public readonly ?string $description = null,
        public readonly ?float $price = null,
    ) {}
}
