<?php

namespace App\Application\Tenancy;

/**
 * Incremento 4: generalizado a N servicios / N recursos (antes, uno de
 * cada, Parte XII/XVI). Cada recurso puede tener su propio horario. Qué
 * recurso presta cada servicio es explícito por servicio
 * (ServiceRegistrationData::$resourceKeys, elegido en la conversación vía
 * ServiceResourceSelectionFlow) — ya no se asume "todo recurso presta todo
 * servicio".
 *
 * B5: sin Channel — registrar una Organization ya no implica vincular el
 * número por el que se hizo el onboarding (el CENTRAL, que no pertenece a
 * ninguna). ownerPhone es lo que identifica al dueño.
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
        public readonly ?string $city,
        public readonly ?string $address,
        public readonly array $services,
        public readonly array $resources,
        public readonly ?string $organizationDescription = null,
    ) {}
}
