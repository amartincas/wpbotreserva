<?php

use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Illuminate\Support\Facades\Log;

function resolverFixtureOrganization(string $name = 'Barbería Don Carlos'): Organization
{
    return Organization::create(['name' => $name, 'owner_phone' => '+573001234567']);
}

function resolverFixtureBooking(Organization $org, ?Resource $resource = null): Booking
{
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877', 'name' => 'Ana']);

    $booking = Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => '2026-10-05 15:00', 'ends_at' => '2026-10-05 15:30',
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);

    if ($resource !== null) {
        BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);
    }

    return $booking->fresh(['bookingResources']);
}

function resolverFixtureResource(Organization $org, ?string $contactPhone = '+573005556677', bool $isActive = true): Resource
{
    $location = $org->locations()->first() ?? Location::create(['organization_id' => $org->id, 'name' => 'Sede']);

    return Resource::create([
        'organization_id' => $org->id, 'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos',
        'contact_phone' => $contactPhone, 'is_active' => $isActive,
    ]);
}

test('resuelve correctamente cuando todo está en orden', function () {
    $org = resolverFixtureOrganization();
    $resource = resolverFixtureResource($org);
    $booking = resolverFixtureBooking($org, $resource);

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->not->toBeNull();
    expect($recipient->contactPhone)->toBe('+573005556677');
    expect($recipient->organization->is($org))->toBeTrue();
    expect($recipient->resourceName)->toBe('Carlos');
});

test('sin BookingResource: no resuelve y loguea warning', function () {
    Log::spy();

    $org = resolverFixtureOrganization();
    $booking = resolverFixtureBooking($org, null);

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with(
        'ProfessionalRecipientResolver: booking sin BookingResource',
        Mockery::on(fn ($ctx) => $ctx['booking_id'] === $booking->id),
    );
});

test('Resource eliminado: la cascada borra el BookingResource, el resolver ve 0 filas y no revienta', function () {
    // resource_id en booking_resources es cascadeOnDelete (confirmado en la
    // auditoría) — borrar el Resource borra también la fila BookingResource,
    // así que el caso real de "recurso eliminado" es indistinguible de "sin
    // BookingResource" una vez consumado. No hay forma de simular un
    // resource_id huérfano sin desactivar la FK, porque la constraint
    // también se aplica en el INSERT, no solo en el DELETE.
    Log::spy();

    $org = resolverFixtureOrganization();
    $resource = resolverFixtureResource($org);
    $booking = resolverFixtureBooking($org, $resource);

    $resource->delete();
    $booking = $booking->fresh(['bookingResources']);

    expect($booking->bookingResources)->toBeEmpty();

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with(
        'ProfessionalRecipientResolver: booking sin BookingResource',
        Mockery::any(),
    );
});

test('Resource inactivo: SÍ resuelve — is_active nunca bloquea una reserva ya asignada', function () {
    $org = resolverFixtureOrganization();
    $resource = resolverFixtureResource($org, isActive: false);
    $booking = resolverFixtureBooking($org, $resource);

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->not->toBeNull();
    expect($recipient->contactPhone)->toBe('+573005556677');
});

test('contact_phone NULL: no resuelve y loguea warning, nunca cae a owner_phone', function () {
    Log::spy();

    $org = resolverFixtureOrganization();
    $resource = resolverFixtureResource($org, contactPhone: null);
    $booking = resolverFixtureBooking($org, $resource);

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with(
        'ProfessionalRecipientResolver: Resource sin contact_phone',
        Mockery::on(fn ($ctx) => $ctx['booking_id'] === $booking->id && $ctx['resource_id'] === $resource->id),
    );
});

test('aislamiento multi-tenant: Resource de otra Organization no se usa — no resuelve y loguea error', function () {
    Log::spy();

    $orgA = resolverFixtureOrganization('Negocio A');
    $orgB = resolverFixtureOrganization('Negocio B');
    $resourceB = resolverFixtureResource($orgB, contactPhone: '+573001112233');

    // Booking real de A, apuntando (dato corrupto/simulado) a un Resource de B.
    $booking = resolverFixtureBooking($orgA, $resourceB);

    $recipient = (new ProfessionalRecipientResolver)->resolve($booking, 'confirmation');

    expect($recipient)->toBeNull();
    Log::shouldHaveReceived('error')->once()->with(
        'ProfessionalRecipientResolver: el Resource pertenece a otra Organization',
        Mockery::on(fn ($ctx) => $ctx['booking_id'] === $booking->id
            && $ctx['booking_organization_id'] === $orgA->id
            && $ctx['resource_id'] === $resourceB->id
            && $ctx['resource_organization_id'] === $orgB->id),
    );
});

test('2 organizaciones con un Resource del mismo nombre pero teléfono distinto nunca se cruzan', function () {
    $orgA = resolverFixtureOrganization('Negocio A');
    $orgB = resolverFixtureOrganization('Negocio B');

    $locationA = Location::create(['organization_id' => $orgA->id, 'name' => 'Sede A']);
    $locationB = Location::create(['organization_id' => $orgB->id, 'name' => 'Sede B']);

    $resourceA = Resource::create([
        'organization_id' => $orgA->id, 'location_id' => $locationA->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => '+573001110000',
    ]);
    Resource::create([
        'organization_id' => $orgB->id, 'location_id' => $locationB->id,
        'resource_type' => ResourceType::HUMAN, 'display_name' => 'Carlos', 'contact_phone' => '+573002220000',
    ]);

    $bookingA = resolverFixtureBooking($orgA, $resourceA);

    $recipient = (new ProfessionalRecipientResolver)->resolve($bookingA, 'confirmation');

    expect($recipient->contactPhone)->toBe('+573001110000');
});
