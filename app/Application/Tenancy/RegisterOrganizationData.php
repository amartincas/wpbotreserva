<?php

namespace App\Application\Tenancy;

use App\Domain\Tenancy\Channel;

/**
 * Incremento 4: generalizado a N servicios / N recursos (antes, uno de
 * cada, Parte XII/XVI). Cada recurso puede tener su propio horario. Qué
 * recurso presta cada servicio es explícito por servicio
 * (ServiceRegistrationData::$resourceKeys, elegido en la conversación vía
 * ServiceResourceSelectionFlow) — ya no se asume "todo recurso presta todo
 * servicio".
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
