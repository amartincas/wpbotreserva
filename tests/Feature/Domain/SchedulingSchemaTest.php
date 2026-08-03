<?php

use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\ScheduleException;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use App\Enums\ScheduleExceptionType;
use Illuminate\Database\QueryException;

function makeOrgWithLocation(): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);

    return [$org, $location];
}

test('un resource pertenece a una organization y opcionalmente a un location', function () {
    [$org, $location] = makeOrgWithLocation();

    $resource = Resource::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN,
        'subtype' => 'estilista',
        'display_name' => 'Carlos',
    ]);

    expect($resource->organization->is($org))->toBeTrue();
    expect($resource->location->is($location))->toBeTrue();
    expect($resource->resource_type)->toBe(ResourceType::HUMAN);
    expect($resource->capacity)->toBe(1);
});

test('un resource puede no tener location (recurso flotante/remoto)', function () {
    [$org] = makeOrgWithLocation();

    $resource = Resource::create([
        'organization_id' => $org->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Asesor remoto',
    ]);

    expect($resource->location)->toBeNull();
});

test('duration_minutes de un service debe ser mayor a cero (CHECK constraint)', function () {
    [$org] = makeOrgWithLocation();

    expect(fn () => Service::create([
        'organization_id' => $org->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 0,
    ]))->toThrow(QueryException::class);
});

test('un service define requisitos de recursos y qué recursos concretos los cubren', function () {
    [$org, $location] = makeOrgWithLocation();

    $service = Service::create([
        'organization_id' => $org->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
    ]);

    $requirement = ServiceResourceRequirement::create([
        'service_id' => $service->id,
        'resource_type' => ResourceType::HUMAN,
        'subtype' => 'estilista',
        'quantity' => 1,
    ]);

    $resource = Resource::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN,
        'subtype' => 'estilista',
        'display_name' => 'Carlos',
    ]);

    $service->resources()->attach($resource->id);

    expect($service->resourceRequirements)->toHaveCount(1);
    expect($requirement->service->is($service))->toBeTrue();
    expect($service->resources)->toHaveCount(1);
    expect($resource->services)->toHaveCount(1);
});

test('resource_schedules exige exactamente un dueño: resource O location, nunca ambos ni ninguno', function () {
    [, $location] = makeOrgWithLocation();

    // Ninguno de los dos.
    expect(fn () => ResourceSchedule::create([
        'weekday' => 1,
        'start_time' => '08:00',
        'end_time' => '18:00',
    ]))->toThrow(QueryException::class);

    // Válido: solo location.
    $schedule = ResourceSchedule::create([
        'location_id' => $location->id,
        'weekday' => 1,
        'start_time' => '08:00',
        'end_time' => '18:00',
    ]);
    expect($schedule->location->is($location))->toBeTrue();
});

test('resource_schedules exige end_time posterior a start_time (CHECK constraint)', function () {
    [, $location] = makeOrgWithLocation();

    expect(fn () => ResourceSchedule::create([
        'location_id' => $location->id,
        'weekday' => 1,
        'start_time' => '18:00',
        'end_time' => '08:00',
    ]))->toThrow(QueryException::class);
});

test('schedule_exception es su propio aggregate y puede apuntar a organization, location o resource', function () {
    [$org, $location] = makeOrgWithLocation();

    $orgWide = ScheduleException::create([
        'organization_id' => $org->id,
        'date' => '2026-12-25',
        'type' => ScheduleExceptionType::HOLIDAY,
        'is_available' => false,
        'reason' => 'Navidad',
    ]);

    expect($orgWide->location)->toBeNull();
    expect($orgWide->resource)->toBeNull();
    expect($orgWide->type)->toBe(ScheduleExceptionType::HOLIDAY);
    expect($org->fresh())->not->toBeNull(); // organization sobrevive a la relación
});
