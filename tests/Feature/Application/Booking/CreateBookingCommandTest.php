<?php

use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;

function createBookingFixtures(): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    ServiceResourceRequirement::create(['service_id' => $service->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);
    $resource = Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos',
    ]);
    $service->resources()->attach($resource->id);
    ResourceSchedule::create([
        'resource_id' => $resource->id,
        'weekday' => CarbonImmutable::parse('2026-09-07')->dayOfWeek,
        'start_time' => '09:00', 'end_time' => '12:00',
    ]);

    return compact('org', 'location', 'service', 'resource');
}

function bookingCommand(): CreateBookingCommand
{
    return new CreateBookingCommand(new BookingScheduler(new AvailabilityCalculator));
}

test('crea la reserva y crea un Customer nuevo si el teléfono no existía', function () {
    $f = createBookingFixtures();
    $data = new CreateBookingData(
        organization: $f['org'], location: $f['location'], service: $f['service'],
        customerPhone: '+573001234567', customerName: 'Ana',
        startsAt: CarbonImmutable::parse('2026-09-07 10:00'), resource: $f['resource'],
    );

    $result = bookingCommand()->handle($data);

    expect($result->bookingId)->toBeInt();
    expect($result->serviceName)->toBe('Corte');
    expect($result->resourceName)->toBe('Carlos');
    expect($result->status)->toBe(BookingStatus::CONFIRMED);

    $customer = Customer::where('organization_id', $f['org']->id)->where('phone', '+573001234567')->firstOrFail();
    expect($customer->name)->toBe('Ana');
    expect($customer->first_seen_at)->not->toBeNull();
    expect($customer->last_interaction_at)->not->toBeNull();
});

test('completa el nombre de un Customer existente que todavía no tenía uno', function () {
    $f = createBookingFixtures();
    Customer::create(['organization_id' => $f['org']->id, 'phone' => '+573001234567', 'name' => null]);

    $data = new CreateBookingData(
        organization: $f['org'], location: $f['location'], service: $f['service'],
        customerPhone: '+573001234567', customerName: 'Ana',
        startsAt: CarbonImmutable::parse('2026-09-07 10:00'), resource: $f['resource'],
    );

    bookingCommand()->handle($data);

    $customer = Customer::where('organization_id', $f['org']->id)->where('phone', '+573001234567')->firstOrFail();
    expect($customer->name)->toBe('Ana');
});

test('reutiliza el Customer existente y actualiza last_interaction_at sin duplicarlo', function () {
    $f = createBookingFixtures();
    $existing = Customer::create(['organization_id' => $f['org']->id, 'phone' => '+573001234567', 'name' => 'Ana Vieja']);

    $data = new CreateBookingData(
        organization: $f['org'], location: $f['location'], service: $f['service'],
        customerPhone: '+573001234567', customerName: null,
        startsAt: CarbonImmutable::parse('2026-09-07 10:00'), resource: $f['resource'],
    );

    bookingCommand()->handle($data);

    expect(Customer::where('organization_id', $f['org']->id)->where('phone', '+573001234567')->count())->toBe(1);
    expect($existing->fresh()->name)->toBe('Ana Vieja'); // no se pisa un nombre ya confirmado
});

test('propaga la excepción de dominio cuando el horario no está disponible', function () {
    $f = createBookingFixtures();
    $data = new CreateBookingData(
        organization: $f['org'], location: $f['location'], service: $f['service'],
        customerPhone: '+573001234567', customerName: 'Ana',
        startsAt: CarbonImmutable::parse('2026-09-07 20:00'), resource: $f['resource'],
    );

    expect(fn () => bookingCommand()->handle($data))
        ->toThrow(SlotNoLongerAvailableException::class);
});
