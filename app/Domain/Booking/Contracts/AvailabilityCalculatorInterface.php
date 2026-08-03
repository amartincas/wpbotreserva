<?php

namespace App\Domain\Booking\Contracts;

use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Detrás de una interfaz desde el día uno (Parte XI punto 2) — no porque
 * haga falta hoy, sino para que un decorador de cache (cuando el tráfico lo
 * justifique) envuelva la implementación real sin tocar un solo call site.
 */
interface AvailabilityCalculatorInterface
{
    /**
     * Horarios libres de un Service en un Location para un día calendario.
     * Si $resource se especifica, solo evalúa ese recurso concreto; si no,
     * evalúa todos los candidatos capaces de prestar el servicio y devuelve
     * la unión (Parte VIII: el caso de 0-1 recurso simultáneo del MVP).
     *
     * @return Collection<int, AvailableSlot>
     */
    public function availableSlots(
        Service $service,
        Location $location,
        CarbonImmutable $date,
        ?Resource $resource = null,
    ): Collection;
}
