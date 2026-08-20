<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Domain\Booking\ActiveBookingsFinder;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;

function activeFinderFixtureOrganization(): Organization
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
        'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    return $org;
}

function activeFinderFixtureBooking(Organization $organization, string $phone, CarbonImmutable $startsAt): App\Domain\Booking\Booking
{
    $result = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: $phone,
        customerName: null,
        startsAt: $startsAt,
        resource: $organization->resources()->first(),
    ));

    return App\Domain\Booking\Booking::findOrFail($result->bookingId);
}

test('devuelve vacío si el teléfono nunca fue cliente de esta organización', function () {
    $organization = activeFinderFixtureOrganization();

    $result = (new ActiveBookingsFinder)->forCustomer($organization, '+573000000000');

    expect($result)->toBeEmpty();
});

test('devuelve las reservas activas ordenadas por fecha', function () {
    $organization = activeFinderFixtureOrganization();
    $later = activeFinderFixtureBooking($organization, '+573001234567', CarbonImmutable::parse('2026-09-07 15:00'));
    $earlier = activeFinderFixtureBooking($organization, '+573001234567', CarbonImmutable::parse('2026-09-07 10:00'));

    $result = (new ActiveBookingsFinder)->forCustomer($organization, '+573001234567');

    expect($result)->toHaveCount(2);
    expect($result->first()->id)->toBe($earlier->id);
    expect($result->last()->id)->toBe($later->id);
});

test('excluye reservas canceladas', function () {
    $organization = activeFinderFixtureOrganization();
    $booking = activeFinderFixtureBooking($organization, '+573001234567', CarbonImmutable::parse('2026-09-07 10:00'));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);

    $result = (new ActiveBookingsFinder)->forCustomer($organization, '+573001234567');

    expect($result)->toBeEmpty();
});

test('nunca mezcla reservas de otra organización aunque el teléfono coincida', function () {
    $organizationA = activeFinderFixtureOrganization();
    $organizationB = activeFinderFixtureOrganization();
    activeFinderFixtureBooking($organizationB, '+573001234567', CarbonImmutable::parse('2026-09-07 10:00'));

    $result = (new ActiveBookingsFinder)->forCustomer($organizationA, '+573001234567');

    expect($result)->toBeEmpty();
});
