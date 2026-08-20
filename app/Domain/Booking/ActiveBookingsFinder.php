<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\CRM\Customer;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

class ActiveBookingsFinder implements ActiveBookingsFinderInterface
{
    public function forCustomer(Organization $organization, string $phone): Collection
    {
        $customer = Customer::where('organization_id', $organization->id)->where('phone', $phone)->first();

        if ($customer === null) {
            return collect();
        }

        return $customer->bookings()
            ->get()
            ->reject(fn (Booking $booking) => $booking->isTerminal())
            ->sortBy('starts_at')
            ->values();
    }
}
