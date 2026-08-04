<?php

namespace App\Application\Booking;

use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use Carbon\CarbonImmutable;

/**
 * Entrada de CreateBookingCommand — nunca expone aggregates de dominio hacia
 * quien llama (Agente Reservas, Hito 6), solo los recibe como parámetros ya
 * resueltos por esa capa superior.
 */
final class CreateBookingData
{
    public function __construct(
        public readonly Organization $organization,
        public readonly Location $location,
        public readonly Service $service,
        public readonly string $customerPhone,
        public readonly ?string $customerName,
        public readonly CarbonImmutable $startsAt,
        public readonly ?Resource $resource = null,
        public readonly ?string $notes = null,
    ) {}
}
