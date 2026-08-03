<?php

use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\ScheduleException;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use App\Enums\ScheduleExceptionType;
use Carbon\CarbonImmutable;

/**
 * Fecha fija de referencia para todos los tests — el weekday SIEMPRE se
 * deriva de esta fecha con ->dayOfWeek, nunca hardcodeado, para no depender
 * de que "2026-09-07 es lunes" sea correcto de memoria.
 */
function availabilityDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-07');
}

function availabilityFixtures(array $service = [], bool $withResource = true): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);
    $svc = Service::create(array_merge([
        'organization_id' => $org->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
        'cancellation_policy' => 'Cancelación gratuita hasta 2 horas antes.',
    ], $service));

    $resource = null;
    if ($withResource) {
        $resource = Resource::create([
            'organization_id' => $org->id,
            'location_id' => $location->id,
            'resource_type' => ResourceType::HUMAN,
            'subtype' => 'estilista',
            'display_name' => 'Carlos',
        ]);

        ServiceResourceRequirement::create([
            'service_id' => $svc->id,
            'resource_type' => ResourceType::HUMAN,
            'subtype' => 'estilista',
            'quantity' => 1,
        ]);

        $svc->resources()->attach($resource->id);

        ResourceSchedule::create([
            'resource_id' => $resource->id,
            'weekday' => availabilityDate()->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
    }

    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    return compact('org', 'location', 'svc', 'resource', 'customer');
}

function makeConfirmedBooking(array $fixtures, string $start, string $end): Booking
{
    $booking = Booking::create([
        'organization_id' => $fixtures['org']->id,
        'location_id' => $fixtures['location']->id,
        'service_id' => $fixtures['svc']->id,
        'customer_id' => $fixtures['customer']->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'duration_minutes' => $fixtures['svc']->duration_minutes,
        'status' => BookingStatus::CONFIRMED,
    ]);

    if ($fixtures['resource']) {
        BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $fixtures['resource']->id]);
    }

    return $booking;
}

test('un horario semanal simple produce slots alineados a la duración del servicio', function () {
    $f = availabilityFixtures();
    $calculator = new AvailabilityCalculator;

    $slots = $calculator->availableSlots($f['svc'], $f['location'], availabilityDate());

    // 09:00-12:00 con turnos de 30 min = 6 slots.
    expect($slots)->toHaveCount(6);
    expect($slots->first()->range->start->format('H:i'))->toBe('09:00');
    expect($slots->last()->range->start->format('H:i'))->toBe('11:30');
    expect($slots->every(fn (AvailableSlot $s) => $s->resource->is($f['resource'])))->toBeTrue();
});

test('un festivo de toda la organización bloquea el día completo', function () {
    $f = availabilityFixtures();

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'date' => availabilityDate()->toDateString(),
        'type' => ScheduleExceptionType::HOLIDAY,
        'is_available' => false,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    expect($slots)->toBeEmpty();
});

test('un bloqueo con rango horario específico solo bloquea esa franja', function () {
    $f = availabilityFixtures();

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'resource_id' => $f['resource']->id,
        'date' => availabilityDate()->toDateString(),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'type' => ScheduleExceptionType::BLOCK,
        'is_available' => false,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->all();

    expect($starts)->toBe(['09:00', '09:30', '11:00', '11:30']);
});

test('una excepción a nivel resource tiene prioridad sobre una a nivel location', function () {
    $f = availabilityFixtures();

    // Location dice "festivo, cerrado todo el día"...
    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'location_id' => $f['location']->id,
        'date' => availabilityDate()->toDateString(),
        'type' => ScheduleExceptionType::HOLIDAY,
        'is_available' => false,
    ]);

    // ...pero ESTE recurso puntual tiene horario especial ese día.
    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'resource_id' => $f['resource']->id,
        'date' => availabilityDate()->toDateString(),
        'start_time' => '09:00',
        'end_time' => '10:00',
        'type' => ScheduleExceptionType::SPECIAL_HOURS,
        'is_available' => true,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    expect($slots)->toHaveCount(2); // 09:00 y 09:30, dentro de la ventana especial de 1h
});

test('una excepción a nivel location aplica cuando no hay una de resource que la reemplace', function () {
    $f = availabilityFixtures();

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'location_id' => $f['location']->id,
        'date' => availabilityDate()->toDateString(),
        'start_time' => '09:00',
        'end_time' => '10:00',
        'type' => ScheduleExceptionType::BLOCK,
        'is_available' => false,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->all();

    expect($starts)->toBe(['10:00', '10:30', '11:00', '11:30']);
});

test('una special_hours sin rango horario no reemplaza nada, cae al horario base', function () {
    $f = availabilityFixtures();

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'resource_id' => $f['resource']->id,
        'date' => availabilityDate()->toDateString(),
        'type' => ScheduleExceptionType::SPECIAL_HOURS,
        'is_available' => true,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    expect($slots)->toHaveCount(6); // horario normal 09:00-12:00 intacto
});

test('un bloqueo horario que no se solapa con una de las ventanas del día la deja intacta', function () {
    $f = availabilityFixtures();
    // Segunda ventana del día, por la tarde — no se solapa con el bloqueo de la mañana.
    ResourceSchedule::create([
        'resource_id' => $f['resource']->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '14:00',
        'end_time' => '15:00',
    ]);

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'resource_id' => $f['resource']->id,
        'date' => availabilityDate()->toDateString(),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'type' => ScheduleExceptionType::BLOCK,
        'is_available' => false,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->sort()->values()->all();

    // Mañana (09:00-12:00 menos 10:00-11:00) + tarde (14:00-15:00) intacta.
    expect($starts)->toBe(['09:00', '09:30', '11:00', '11:30', '14:00', '14:30']);
});

test('special_hours reemplaza el horario normal del día, no lo combina', function () {
    $f = availabilityFixtures();

    ScheduleException::create([
        'organization_id' => $f['org']->id,
        'resource_id' => $f['resource']->id,
        'date' => availabilityDate()->toDateString(),
        'start_time' => '14:00',
        'end_time' => '16:00',
        'type' => ScheduleExceptionType::SPECIAL_HOURS,
        'is_available' => true,
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->all();

    // El horario normal (09:00-12:00) queda reemplazado por el especial (14:00-16:00).
    expect($starts)->toBe(['14:00', '14:30', '15:00', '15:30']);
});

test('una reserva existente remueve ese slot de la disponibilidad', function () {
    $f = availabilityFixtures();
    $date = availabilityDate()->format('Y-m-d');
    makeConfirmedBooking($f, "{$date} 10:00", "{$date} 10:30");

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->all();

    expect($starts)->not->toContain('10:00');
    expect($starts)->toHaveCount(5);
});

test('el buffer_minutes del servicio extiende el bloqueo después de una reserva existente', function () {
    $f = availabilityFixtures(['buffer_minutes' => 15]);
    $date = availabilityDate()->format('Y-m-d');
    makeConfirmedBooking($f, "{$date} 10:00", "{$date} 10:30");

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    $starts = $slots->map(fn (AvailableSlot $s) => $s->range->start->format('H:i'))->all();

    // 10:00 ocupado, y 10:30 también porque el buffer de 15min (hasta 10:45)
    // se solapa con el slot 10:30-11:00.
    expect($starts)->not->toContain('10:00');
    expect($starts)->not->toContain('10:30');
    expect($starts)->toContain('11:00');
});

test('capacity_per_slot mayor a 1 permite reservas simultáneas hasta el límite', function () {
    $f = availabilityFixtures(['capacity_per_slot' => 2]);
    $date = availabilityDate()->format('Y-m-d');
    makeConfirmedBooking($f, "{$date} 10:00", "{$date} 10:30");

    // Con 1 reserva ya puesta y capacidad 2, el slot 10:00 sigue disponible.
    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    expect($slots->pluck('range')->map(fn ($r) => $r->start->format('H:i')))->toContain('10:00');

    makeConfirmedBooking($f, "{$date} 10:00", "{$date} 10:30");

    // Con 2 reservas puestas y capacidad 2, el slot 10:00 ya no está disponible.
    $slotsAfter = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());
    expect($slotsAfter->pluck('range')->map(fn ($r) => $r->start->format('H:i')))->not->toContain('10:00');
});

test('un resource sin horario propio hereda el horario general del location', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);
    $svc = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 60]);
    $resource = Resource::create([
        'organization_id' => $org->id,
        'location_id' => $location->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Carlos',
    ]);
    ServiceResourceRequirement::create(['service_id' => $svc->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);
    $svc->resources()->attach($resource->id);

    // Horario a nivel LOCATION, no del resource.
    ResourceSchedule::create([
        'location_id' => $location->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '08:00',
        'end_time' => '09:00',
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($svc, $location, availabilityDate());

    expect($slots)->toHaveCount(1);
    expect($slots->first()->range->start->format('H:i'))->toBe('08:00');
});

test('un servicio sin resource_requirements usa disponibilidad basada en location, sin recurso', function () {
    $f = availabilityFixtures(withResource: false);

    ResourceSchedule::create([
        'location_id' => $f['location']->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    expect($slots)->toHaveCount(2);
    expect($slots->every(fn (AvailableSlot $s) => $s->resource === null))->toBeTrue();
});

test('múltiples recursos candidatos devuelven la unión de su disponibilidad', function () {
    $f = availabilityFixtures();

    $second = Resource::create([
        'organization_id' => $f['org']->id,
        'location_id' => $f['location']->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'María',
    ]);
    $f['svc']->resources()->attach($second->id);
    ResourceSchedule::create([
        'resource_id' => $second->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '09:30',
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    // 6 de Carlos (09:00-12:00) + 1 de María (09:00-09:30) = 7.
    expect($slots)->toHaveCount(7);
    expect($slots->pluck('resource.id')->unique()->count())->toBe(2);
});

test('pedir un recurso explícito filtra solo ese recurso, ignorando otros candidatos', function () {
    $f = availabilityFixtures();
    $second = Resource::create([
        'organization_id' => $f['org']->id,
        'location_id' => $f['location']->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'María',
    ]);
    $f['svc']->resources()->attach($second->id);
    ResourceSchedule::create([
        'resource_id' => $second->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate(), $f['resource']);

    expect($slots->every(fn (AvailableSlot $s) => $s->resource->is($f['resource'])))->toBeTrue();
});

test('un resource inactivo o de otro location no aparece como candidato', function () {
    $f = availabilityFixtures();

    $otherLocation = Location::create(['organization_id' => $f['org']->id, 'name' => 'Otra sede']);
    $elsewhere = Resource::create([
        'organization_id' => $f['org']->id,
        'location_id' => $otherLocation->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'En otra sede',
    ]);
    $f['svc']->resources()->attach($elsewhere->id);

    $inactive = Resource::create([
        'organization_id' => $f['org']->id,
        'location_id' => $f['location']->id,
        'resource_type' => ResourceType::HUMAN,
        'display_name' => 'Inactivo',
        'is_active' => false,
    ]);
    $f['svc']->resources()->attach($inactive->id);
    ResourceSchedule::create([
        'resource_id' => $inactive->id,
        'weekday' => availabilityDate()->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], availabilityDate());

    expect($slots->pluck('resource.id')->unique()->all())->toBe([$f['resource']->id]);
});
