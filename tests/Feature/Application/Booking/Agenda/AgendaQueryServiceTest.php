<?php

use App\Application\Booking\Agenda\AgendaQueryService;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

function agendaQueryFixtureBooking(Organization $organization, CarbonImmutable $startsAt, BookingStatus $status, ?Resource $resource = null): Booking
{
    static $sequence = 0;
    $sequence++;

    $location = Location::create(['organization_id' => $organization->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $organization->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $organization->id, 'phone' => '+5730012340'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT)]);

    $booking = Booking::create([
        'organization_id' => $organization->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(30),
        'duration_minutes' => 30, 'status' => $status,
    ]);

    if ($resource !== null) {
        BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);
    }

    return $booking;
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('sin Resource, devuelve todas las reservas de la Organization en esa fecha', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    agendaQueryFixtureBooking($organization, now()->setTime(10, 0), BookingStatus::CONFIRMED);
    agendaQueryFixtureBooking($organization, now()->setTime(11, 0), BookingStatus::CONFIRMED);

    $bookings = (new AgendaQueryService)->forDate($organization, now()->toImmutable());

    expect($bookings)->toHaveCount(2);
});

test('con Resource, filtra solo las reservas de ese profesional', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $resourceA = Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos']);
    $resourceB = Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Maria']);
    $bookingA = agendaQueryFixtureBooking($organization, now()->setTime(10, 0), BookingStatus::CONFIRMED, $resourceA);
    agendaQueryFixtureBooking($organization, now()->setTime(11, 0), BookingStatus::CONFIRMED, $resourceB);

    $bookings = (new AgendaQueryService)->forDate($organization, now()->toImmutable(), $resourceA);

    expect($bookings)->toHaveCount(1);
    expect($bookings->first()->id)->toBe($bookingA->id);
});

test('CANCELLED se excluye siempre', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    agendaQueryFixtureBooking($organization, now()->setTime(10, 0), BookingStatus::CANCELLED);

    $bookings = (new AgendaQueryService)->forDate($organization, now()->toImmutable());

    expect($bookings)->toBeEmpty();
});

test('COMPLETED y NO_SHOW se incluyen', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    agendaQueryFixtureBooking($organization, now()->setTime(10, 0), BookingStatus::COMPLETED);
    agendaQueryFixtureBooking($organization, now()->setTime(11, 0), BookingStatus::NO_SHOW);

    $bookings = (new AgendaQueryService)->forDate($organization, now()->toImmutable());

    expect($bookings)->toHaveCount(2);
});

test('aislamiento por Organization: no devuelve reservas de otra Organization', function () {
    $orgA = Organization::create(['name' => 'Negocio A']);
    $orgB = Organization::create(['name' => 'Negocio B']);
    agendaQueryFixtureBooking($orgB, now()->setTime(10, 0), BookingStatus::CONFIRMED);

    $bookings = (new AgendaQueryService)->forDate($orgA, now()->toImmutable());

    expect($bookings)->toBeEmpty();
});

test('ordena por starts_at', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    agendaQueryFixtureBooking($organization, now()->setTime(15, 0), BookingStatus::CONFIRMED);
    agendaQueryFixtureBooking($organization, now()->setTime(9, 0), BookingStatus::CONFIRMED);

    $bookings = (new AgendaQueryService)->forDate($organization, now()->toImmutable());

    expect($bookings->pluck('starts_at')->map->format('H:i')->all())->toBe(['09:00', '15:00']);
});
