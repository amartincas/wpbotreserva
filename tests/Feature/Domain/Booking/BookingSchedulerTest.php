<?php

use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Exceptions\InvalidBookingRequestException;
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
use Illuminate\Support\Facades\Event;

function schedulerDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-07');
}

function schedulerFixtures(int $resourceCount = 1): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);
    $svc = Service::create([
        'organization_id' => $org->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
        'cancellation_policy' => 'Cancelación gratuita hasta 2 horas antes.',
    ]);
    ServiceResourceRequirement::create(['service_id' => $svc->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);

    $resources = collect(range(1, $resourceCount))->map(function ($i) use ($org, $location, $svc) {
        $resource = Resource::create([
            'organization_id' => $org->id,
            'location_id' => $location->id,
            'resource_type' => ResourceType::HUMAN,
            'display_name' => "Estilista {$i}",
        ]);
        $svc->resources()->attach($resource->id);
        ResourceSchedule::create([
            'resource_id' => $resource->id,
            'weekday' => schedulerDate()->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        return $resource;
    });

    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    return compact('org', 'location', 'svc', 'resources', 'customer') + ['resource' => $resources->first()];
}

function schedulerFor(): BookingScheduler
{
    return new BookingScheduler(new AvailabilityCalculator);
}

test('una reserva exitosa crea Booking + BookingResource, snapshotea datos y dispara BookingConfirmed', function () {
    Event::fake([BookingConfirmed::class]);
    $f = schedulerFixtures();
    $startsAt = schedulerDate()->setTime(10, 0);

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect($booking->exists)->toBeTrue();
    expect($booking->status)->toBe(BookingStatus::CONFIRMED);
    expect($booking->duration_minutes)->toBe(30);
    expect($booking->cancellation_policy_snapshot)->toBe('Cancelación gratuita hasta 2 horas antes.');
    expect($booking->ends_at->format('H:i'))->toBe('10:30');
    expect($booking->bookingResources)->toHaveCount(1);
    expect($booking->bookingResources->first()->resource_id)->toBe($f['resource']->id);

    Event::assertDispatched(BookingConfirmed::class, fn ($event) => $event->booking->is($booking));
});

test('reservar fuera del horario disponible lanza SlotNoLongerAvailableException', function () {
    $f = schedulerFixtures();
    $outsideHours = schedulerDate()->setTime(20, 0); // el horario es 09:00-12:00

    expect(fn () => schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $outsideHours, $f['resource']))
        ->toThrow(SlotNoLongerAvailableException::class);
});

test('reservar un slot ya ocupado del mismo recurso rechaza la segunda reserva (doble reserva prevenida)', function () {
    $f = schedulerFixtures();
    $startsAt = schedulerDate()->setTime(10, 0);

    schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect(fn () => schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']))
        ->toThrow(SlotNoLongerAvailableException::class);

    expect(Booking::count())->toBe(1);
});

test('un recurso que no puede prestar el servicio lanza InvalidBookingRequestException', function () {
    $f = schedulerFixtures();
    $otherOrg = Organization::create(['name' => 'Otro negocio']);
    $unrelatedResource = Resource::create([
        'organization_id' => $otherOrg->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'No relacionado',
    ]);

    expect(fn () => schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $unrelatedResource))
        ->toThrow(InvalidBookingRequestException::class);
});

test('sin recurso explícito, si TODOS los candidatos ya están ocupados, rechaza en vez de elegir cualquiera', function () {
    $f = schedulerFixtures(resourceCount: 2);
    $startsAt = schedulerDate()->setTime(10, 0);

    foreach ($f['resources'] as $resource) {
        schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $resource);
    }

    expect(fn () => schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt))
        ->toThrow(SlotNoLongerAvailableException::class);
});

test('un servicio sin resource_requirements se reserva sin asignar ningún recurso', function () {
    $org = Organization::create(['name' => 'Consultoría X']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede única']);
    $svc = Service::create(['organization_id' => $org->id, 'name' => 'Asesoría telefónica', 'duration_minutes' => 30]);
    ResourceSchedule::create([
        'location_id' => $location->id,
        'weekday' => schedulerDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573009998877']);

    $booking = schedulerFor()->schedule($svc, $location, $customer, schedulerDate()->setTime(9, 0));

    expect($booking->exists)->toBeTrue();
    expect($booking->bookingResources)->toHaveCount(0);
});

test('sin recurso explícito, el scheduler elige automáticamente uno disponible', function () {
    $f = schedulerFixtures(resourceCount: 2);

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0));

    expect($f['resources']->pluck('id'))->toContain($booking->bookingResources->first()->resource_id);
});

test('si el primer candidato ya no está libre bajo lock, prueba con el siguiente', function () {
    $f = schedulerFixtures(resourceCount: 2);
    $startsAt = schedulerDate()->setTime(10, 0);
    [$first, $second] = $f['resources']->all();

    // Ocupa explícitamente el primer recurso en ese horario.
    schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $first);

    // Sin pedir recurso explícito, debe caer en el segundo, no fallar.
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt);

    expect($booking->bookingResources->first()->resource_id)->toBe($second->id);
});

test('capacity_per_slot permite una segunda reserva simultánea y rechaza la que excede el límite', function () {
    $org = Organization::create(['name' => 'Spa Relax']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede única']);
    $svc = Service::create([
        'organization_id' => $org->id,
        'name' => 'Clase grupal de yoga',
        'duration_minutes' => 60,
        'capacity_per_slot' => 2,
    ]);
    ResourceSchedule::create([
        'location_id' => $location->id,
        'weekday' => schedulerDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);
    $customerA = Customer::create(['organization_id' => $org->id, 'phone' => '+573001111111']);
    $customerB = Customer::create(['organization_id' => $org->id, 'phone' => '+573002222222']);
    $customerC = Customer::create(['organization_id' => $org->id, 'phone' => '+573003333333']);
    $startsAt = schedulerDate()->setTime(9, 0);

    schedulerFor()->schedule($svc, $location, $customerA, $startsAt);
    schedulerFor()->schedule($svc, $location, $customerB, $startsAt);

    expect(fn () => schedulerFor()->schedule($svc, $location, $customerC, $startsAt))
        ->toThrow(SlotNoLongerAvailableException::class);
});
