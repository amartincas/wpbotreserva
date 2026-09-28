<?php

use App\Application\Conversations\InfoNegocio\BusinessContextBuilder;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;

test('sin descripción registrada, lo dice explícitamente en vez de omitirlo en silencio', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('Nombre del negocio: Barbería Don Carlos');
    expect($context)->toContain('(sin descripción registrada)');
});

test('con descripción registrada, la incluye tal cual', function () {
    $organization = Organization::create([
        'name' => 'Barbería Don Carlos',
        'description' => 'Barbería especializada en cortes clásicos y afeitado a navaja.',
    ]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('Descripción: Barbería especializada en cortes clásicos y afeitado a navaja.');
});

test('sin ubicaciones registradas, lo dice explícitamente', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('(sin ubicación registrada)');
});

test('con ubicación registrada, incluye dirección y ciudad', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    Location::create([
        'organization_id' => $organization->id,
        'name' => 'Sede principal',
        'address' => 'Cra 7 # 45-12',
        'city' => 'Bogotá',
    ]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('Cra 7 # 45-12, Bogotá');
});

test('sin servicios registrados, lo dice explícitamente', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('(sin servicios registrados)');
});

test('un servicio con precio lo muestra formateado, y con descripción la incluye', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    Service::create([
        'organization_id' => $organization->id,
        'name' => 'Corte de cabello',
        'description' => 'Incluye lavado.',
        'duration_minutes' => 30,
        'price' => 45000,
    ]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('Corte de cabello (30 min)');
    expect($context)->toContain('precio: $45.000');
    expect($context)->toContain('Incluye lavado.');
});

test('un servicio sin precio registrado lo marca explícitamente como "no registrado" — nunca lo omite ni inventa uno', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    Service::create([
        'organization_id' => $organization->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
    ]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('precio: no registrado');
});

test('varios servicios aparecen todos, cada uno en su propia línea', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    Service::create(['organization_id' => $organization->id, 'name' => 'Corte de cabello', 'duration_minutes' => 30]);
    Service::create(['organization_id' => $organization->id, 'name' => 'Barba', 'duration_minutes' => 20]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('- Corte de cabello');
    expect($context)->toContain('- Barba');
});

test('sin recursos registrados, lo dice explícitamente', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('(sin recursos registrados)');
});

test('el horario declarado de un recurso se formatea en orden lunes..domingo, con nombres de día en español', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $resource = Resource::create([
        'organization_id' => $organization->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Carlos',
    ]);
    ResourceSchedule::create(['resource_id' => $resource->id, 'weekday' => 5, 'start_time' => '14:00:00', 'end_time' => '20:00:00']);
    ResourceSchedule::create(['resource_id' => $resource->id, 'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('- Carlos: lunes de 09:00 a 17:00, viernes de 14:00 a 20:00');
});

test('un recurso sin horario declarado lo dice explícitamente', function () {
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    Resource::create([
        'organization_id' => $organization->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Carlos',
    ]);

    $context = (new BusinessContextBuilder)->build($organization);

    expect($context)->toContain('- Carlos: (sin horario registrado)');
});

test('nunca mezcla datos de otra organización (multi-tenant)', function () {
    $organizationA = Organization::create(['name' => 'Barbería Don Carlos', 'description' => 'Descripción A']);
    Service::create(['organization_id' => $organizationA->id, 'name' => 'Corte de cabello', 'duration_minutes' => 30]);

    $organizationB = Organization::create(['name' => 'Spa Relax', 'description' => 'Descripción B']);
    Service::create(['organization_id' => $organizationB->id, 'name' => 'Masaje', 'duration_minutes' => 60]);

    $context = (new BusinessContextBuilder)->build($organizationA);

    expect($context)->toContain('Barbería Don Carlos');
    expect($context)->toContain('Corte de cabello');
    expect($context)->not->toContain('Spa Relax');
    expect($context)->not->toContain('Masaje');
    expect($context)->not->toContain('Descripción B');
});
