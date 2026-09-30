<?php

use App\Application\Booking\Agenda\ProfessionalResolver;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Facades\Log;

function professionalResolverFixtureResource(Organization $organization, string $contactPhone, string $name = 'Carlos'): Resource
{
    return Resource::create([
        'organization_id' => $organization->id,
        'resource_type' => 'HUMAN',
        'display_name' => $name,
        'contact_phone' => $contactPhone,
    ]);
}

test('un Resource con ese contact_phone resuelve correctamente', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $resource = professionalResolverFixtureResource($organization, '+573001111111');

    $resolved = (new ProfessionalResolver)->resolveFor($organization, '+573001111111');

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($resource->id);
});

test('teléfono desconocido no resuelve a ningún Resource', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    professionalResolverFixtureResource($organization, '+573001111111');

    $resolved = (new ProfessionalResolver)->resolveFor($organization, '+573009999999');

    expect($resolved)->toBeNull();
});

test('dos Resources con el mismo contact_phone en la misma Organization: fail-closed, nunca elige uno arbitrariamente', function () {
    Log::spy();

    $organization = Organization::create(['name' => 'Negocio']);
    $first = professionalResolverFixtureResource($organization, '+573001111111', 'Carlos');
    $second = professionalResolverFixtureResource($organization, '+573001111111', 'Carlos 2');

    $resolved = (new ProfessionalResolver)->resolveFor($organization, '+573001111111');

    expect($resolved)->toBeNull();

    Log::shouldHaveReceived('warning')->once()->with(
        'ProfessionalResolver: contact_phone ambiguo dentro de la misma Organization',
        Mockery::on(fn ($context) => $context['organization_id'] === $organization->id
            && in_array($first->id, $context['resource_ids'], true)
            && in_array($second->id, $context['resource_ids'], true)),
    );
});

test('aislamiento por Organization: el mismo contact_phone en otra Organization no resuelve', function () {
    $orgA = Organization::create(['name' => 'Negocio A']);
    $orgB = Organization::create(['name' => 'Negocio B']);
    professionalResolverFixtureResource($orgB, '+573001111111');

    $resolved = (new ProfessionalResolver)->resolveFor($orgA, '+573001111111');

    expect($resolved)->toBeNull();
});

test('Resource inactivo igual resuelve (mismo criterio que notificaciones de Fase 2B)', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $resource = professionalResolverFixtureResource($organization, '+573001111111');
    $resource->update(['is_active' => false]);

    $resolved = (new ProfessionalResolver)->resolveFor($organization, '+573001111111');

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($resource->id);
});
