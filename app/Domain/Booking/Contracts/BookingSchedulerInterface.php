<?php

namespace App\Domain\Booking\Contracts;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Exceptions\InvalidBookingRequestException;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use Carbon\CarbonImmutable;

/**
 * Único punto de entrada permitido para crear una reserva (Parte III/XI
 * punto 3) — nadie más inserta en `bookings` directamente. Alcance
 * deliberadamente angosto: solo agenda. Cancelar/reprogramar es Incremento 2
 * (fuera de este roadmap de 8 hitos); permisos/entitlements/depósitos viven
 * en la capa de Application (Hito 3) o en Payments, nunca acá (Parte XI
 * punto 3 — la regla que evita que esto se vuelva un God Service).
 */
interface BookingSchedulerInterface
{
    /**
     * @throws InvalidBookingRequestException si el recurso no puede prestar el servicio
     * @throws SlotNoLongerAvailableException si, revalidado bajo lock, el horario ya no está libre
     */
    public function schedule(
        Service $service,
        Location $location,
        Customer $customer,
        CarbonImmutable $startsAt,
        ?Resource $resource = null,
        ?string $notes = null,
    ): Booking;
}
