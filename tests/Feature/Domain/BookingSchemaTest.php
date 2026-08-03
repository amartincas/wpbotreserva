<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingCreatedVia;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Illuminate\Database\QueryException;

function bookingFixtures(): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567', 'name' => 'Ana']);
    $resource = Resource::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Carlos',
    ]);

    return compact('org', 'location', 'service', 'customer', 'resource');
}

test('una booking se crea con sus relaciones y valores por defecto', function () {
    ['org' => $org, 'location' => $location, 'service' => $service, 'customer' => $customer] = bookingFixtures();

    $booking = Booking::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-01 15:00:00',
        'ends_at' => '2026-09-01 15:30:00',
        'duration_minutes' => 30,
        'status' => BookingStatus::PENDING,
    ]);

    expect($booking->organization->is($org))->toBeTrue();
    expect($booking->location->is($location))->toBeTrue();
    expect($booking->service->is($service))->toBeTrue();
    expect($booking->customer->is($customer))->toBeTrue();
    expect($booking->status)->toBe(BookingStatus::PENDING);
    expect($booking->created_via)->toBe(BookingCreatedVia::WHATSAPP);
    expect($booking->isTerminal())->toBeFalse();
    expect($customer->bookings)->toHaveCount(1);
});

test('ends_at debe ser posterior a starts_at (CHECK constraint — invariante central)', function () {
    ['org' => $org, 'location' => $location, 'service' => $service, 'customer' => $customer] = bookingFixtures();

    expect(fn () => Booking::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-01 15:30:00',
        'ends_at' => '2026-09-01 15:00:00',
        'duration_minutes' => 30,
        'status' => BookingStatus::PENDING,
    ]))->toThrow(QueryException::class);
});

test('estados terminales de BookingStatus están correctamente clasificados', function () {
    expect(BookingStatus::CONFIRMED->isTerminal())->toBeFalse();
    expect(BookingStatus::CANCELLED->isTerminal())->toBeTrue();
    expect(BookingStatus::COMPLETED->isTerminal())->toBeTrue();
    expect(BookingStatus::NO_SHOW->isTerminal())->toBeTrue();
});

test('booking_resources registra qué recursos concretos quedaron asignados a una reserva', function () {
    ['org' => $org, 'location' => $location, 'service' => $service, 'customer' => $customer, 'resource' => $resource] = bookingFixtures();

    $booking = Booking::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-01 15:00:00',
        'ends_at' => '2026-09-01 15:30:00',
        'duration_minutes' => 30,
        'status' => BookingStatus::CONFIRMED,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    expect($booking->bookingResources)->toHaveCount(1);
    expect($booking->bookingResources->first()->resource->is($resource))->toBeTrue();
    expect($resource->bookingResources)->toHaveCount(1);
});

test('un mismo resource no puede asignarse dos veces a la misma booking (unique)', function () {
    ['org' => $org, 'location' => $location, 'service' => $service, 'customer' => $customer, 'resource' => $resource] = bookingFixtures();

    $booking = Booking::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => '2026-09-01 15:00:00',
        'ends_at' => '2026-09-01 15:30:00',
        'duration_minutes' => 30,
        'status' => BookingStatus::CONFIRMED,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    expect(fn () => BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]))
        ->toThrow(QueryException::class);
});
