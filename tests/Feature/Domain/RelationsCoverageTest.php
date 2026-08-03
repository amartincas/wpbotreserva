<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\ScheduleException;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Shared\PhoneNumberCast;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use App\Enums\ScheduleExceptionType;
use App\Models\User;

/**
 * Relaciones Eloquent que ya están probadas indirectamente (a través de
 * BookingSchedulerTest/AvailabilityCalculatorTest) pero nunca invocadas
 * explícitamente desde el lado "dueño" — se cierran acá para que la
 * cobertura del dominio no tenga puntos ciegos sin ejercitar.
 */
test('Organization expone resources, services, customers y bookings', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $resource = Resource::create(['organization_id' => $org->id, 'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);
    Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    expect($org->resources)->toHaveCount(1)->and($org->resources->first()->is($resource))->toBeTrue();
    expect($org->services)->toHaveCount(1)->and($org->services->first()->is($service))->toBeTrue();
    expect($org->customers)->toHaveCount(1)->and($org->customers->first()->is($customer))->toBeTrue();
    expect($org->bookings)->toHaveCount(1);
});

test('Location expone resources, schedules y scheduleExceptions', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $resource = Resource::create(['organization_id' => $org->id, 'location_id' => $location->id, 'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos']);
    ResourceSchedule::create(['location_id' => $location->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);
    ScheduleException::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'date' => '2026-12-25',
        'type' => ScheduleExceptionType::HOLIDAY, 'is_available' => false,
    ]);

    expect($location->resources)->toHaveCount(1)->and($location->resources->first()->is($resource))->toBeTrue();
    expect($location->schedules)->toHaveCount(1);
    expect($location->scheduleExceptions)->toHaveCount(1);
});

test('Resource expone user, schedules y scheduleExceptions', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $user = User::create(['name' => 'Carlos', 'email' => 'carlos@example.com', 'password' => 'secret']);
    $resource = Resource::create([
        'organization_id' => $org->id, 'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'user_id' => $user->id,
    ]);
    ResourceSchedule::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);
    ScheduleException::create([
        'organization_id' => $org->id, 'resource_id' => $resource->id, 'date' => '2026-12-25',
        'type' => ScheduleExceptionType::HOLIDAY, 'is_available' => false,
    ]);

    expect($resource->user->is($user))->toBeTrue();
    expect($resource->schedules)->toHaveCount(1);
    expect($resource->scheduleExceptions)->toHaveCount(1);
});

test('ResourceSchedule expone location cuando el horario es del local, no de un resource', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $schedule = ResourceSchedule::create(['location_id' => $location->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

    expect($schedule->location->is($location))->toBeTrue();
});

test('ResourceSchedule expone resource cuando el horario es propio de un resource', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $resource = Resource::create(['organization_id' => $org->id, 'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos']);
    $schedule = ResourceSchedule::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

    expect($schedule->resource->is($resource))->toBeTrue();
});

test('Service expone bookings', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);
    Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    expect($service->bookings)->toHaveCount(1);
});

test('PhoneNumberCast::set acepta tanto un string como una instancia de PhoneNumber ya construida', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);

    $viaString = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);
    $viaObject = Customer::create(['organization_id' => $org->id, 'phone' => new PhoneNumber('+573007654321')]);

    expect($viaString->phone->value())->toBe('+573001234567');
    expect($viaObject->phone->value())->toBe('+573007654321');
});

test('PhoneNumberCast::get devuelve null cuando el valor almacenado es null', function () {
    $cast = new PhoneNumberCast;

    expect($cast->get(new Customer, 'phone', null, []))->toBeNull();
    expect($cast->set(new Customer, 'phone', null, []))->toBeNull();
});

test('BookingResource expone fulfillsRequirement', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $requirement = ServiceResourceRequirement::create(['service_id' => $service->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);
    $resource = Resource::create(['organization_id' => $org->id, 'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos']);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);
    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-09-07 10:00', 'ends_at' => '2026-09-07 10:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);
    $bookingResource = BookingResource::create([
        'booking_id' => $booking->id, 'resource_id' => $resource->id, 'fulfills_requirement_id' => $requirement->id,
    ]);

    expect($bookingResource->fulfillsRequirement->is($requirement))->toBeTrue();
    expect($bookingResource->booking->is($booking))->toBeTrue();
});
