<?php

namespace App\Application\Tenancy;

use App\Domain\Tenancy\Channel;

/**
 * Incremento 4: generalizado a N servicios / N recursos (antes, uno de
 * cada, Parte XII/XVI). Cada recurso puede tener su propio horario; todo
 * recurso queda habilitado para prestar todo servicio (el caso común —
 * cualquier estilista puede hacer cualquier corte) en vez de pedir la
 * asignación fina por conversación, que multiplicaría las preguntas por
 * R×S sin que ningún piloto real lo haya necesitado todavía.
 */
final class RegisterOrganizationData
{
    /**
     * @param  ServiceRegistrationData[]  $services
     * @param  ResourceRegistrationData[]  $resources
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly string $ownerPhone,
        public readonly Channel $channel,
        public readonly ?string $city,
        public readonly ?string $address,
        public readonly array $services,
        public readonly array $resources,
    ) {}
}
