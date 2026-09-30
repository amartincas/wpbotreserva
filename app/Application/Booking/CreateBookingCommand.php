<?php

namespace App\Application\Booking;

use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\CRM\Customer;

/**
 * Único punto de entrada para que un canal (WhatsApp hoy, otro mañana)
 * cree una reserva — nunca invoca BookingScheduler directamente desde un
 * Agente (Parte IX punto 3). Orquesta: resolver/crear el Customer →
 * invocar el dominio → devolver un resultado plano.
 */
class CreateBookingCommand
{
    public function __construct(private readonly BookingSchedulerInterface $scheduler) {}

    public function handle(CreateBookingData $data): CreateBookingResult
    {
        $customer = $this->findOrCreateCustomer($data);

        // Única frontera de normalización de timezone para creación
        // (corrección transversal de timezone): $data->startsAt puede llegar
        // con el timezone de la Organization todavía adjunto (resuelto por
        // DateFieldExtractor) — se normaliza acá, antes de que
        // BookingScheduler/Eloquent lo persistan, para que el instante se
        // guarde de forma consistente con config('app.timezone') (mismo
        // criterio que ya usan los listeners de Fase 2B al convertir para
        // mostrar, pero acá para persistir). setTimezone() nunca cambia el
        // instante real, solo la representación.
        $startsAt = $data->startsAt->setTimezone(config('app.timezone'));

        $booking = $this->scheduler->schedule(
            $data->service,
            $data->location,
            $customer,
            $startsAt,
            $data->resource,
            $data->notes,
        );

        return CreateBookingResult::fromBooking($booking);
    }

    private function findOrCreateCustomer(CreateBookingData $data): Customer
    {
        $customer = Customer::firstOrCreate(
            ['organization_id' => $data->organization->id, 'phone' => $data->customerPhone],
            ['name' => $data->customerName, 'first_seen_at' => now()],
        );

        if ($data->customerName && ! $customer->name) {
            $customer->name = $data->customerName;
        }

        $customer->last_interaction_at = now();
        $customer->save();

        return $customer;
    }
}
