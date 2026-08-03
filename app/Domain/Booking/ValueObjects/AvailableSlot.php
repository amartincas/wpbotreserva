<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Scheduling\Resource;

/**
 * Resultado de AvailabilityCalculator — un horario libre concreto, y con qué
 * recurso (si el servicio necesita uno). $resource es null cuando el
 * servicio no tiene ServiceResourceRequirement (disponibilidad basada solo
 * en capacidad del Location).
 */
final class AvailableSlot
{
    public function __construct(
        public readonly TimeRange $range,
        public readonly ?Resource $resource = null,
    ) {}
}
