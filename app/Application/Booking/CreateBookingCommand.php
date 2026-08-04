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

        $booking = $this->scheduler->schedule(
            $data->service,
            $data->location,
            $customer,
            $data->startsAt,
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
