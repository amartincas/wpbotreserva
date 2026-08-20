<?php

namespace App\Domain\Booking\Contracts;

use App\Domain\Booking\Booking;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

/**
 * Detrás de una interfaz desde el día uno (Parte XI punto 2), mismo
 * criterio que AvailabilityCalculatorInterface — dos consumidores reales
 * ya la necesitan (GestionReservaAgent, InboundMessageRouter) y no deben
 * mantener cada uno su propia noción de "qué cuenta como reserva activa".
 */
interface ActiveBookingsFinderInterface
{
    /**
     * @return Collection<int, Booking> ordenadas por starts_at, sin las terminales
     */
    public function forCustomer(Organization $organization, string $phone): Collection;
}
