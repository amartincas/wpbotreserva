<?php

use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
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

/**
 * Corrección de timezone en disponibilidad — mismo patrón que
 * schedulerFixtures(), con Organization.timezone explícito y horario del
 * recurso ACOTADO (nunca 24h, que enmascararía el bug). $startTime/$endTime
 * son horas LOCALES de Organization, exactamente como las entendería el
 * dueño del negocio al configurar su horario.
 */
function schedulerFixturesWithOrgTimezone(string $timezone, string $startTime = '09:00', string $endTime = '17:00', int $resourceCount = 1): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'timezone' => $timezone]);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede Chapinero']);
    $svc = Service::create([
        'organization_id' => $org->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
    ]);
    ServiceResourceRequirement::create(['service_id' => $svc->id, 'resource_type' => ResourceType::HUMAN, 'quantity' => 1]);

    $resources = collect(range(1, $resourceCount))->map(function ($i) use ($org, $location, $svc, $startTime, $endTime, $timezone) {
        $resource = Resource::create([
            'organization_id' => $org->id,
            'location_id' => $location->id,
            'resource_type' => ResourceType::HUMAN,
            'display_name' => "Estilista {$i}",
        ]);
        $svc->resources()->attach($resource->id);
        // weekday se calcula en timezone de Organization a propósito: es el
        // mismo criterio que usaría el dueño al configurar "lunes 9 a 17".
        ResourceSchedule::create([
            'resource_id' => $resource->id,
            'weekday' => CarbonImmutable::parse('2026-09-07 12:00:00', $timezone)->dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return $resource;
    });

    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    return compact('org', 'location', 'svc', 'resources', 'customer') + ['resource' => $resources->first()];
}

/**
 * Instante local de Organization, ya normalizado a config('app.timezone')
 * — replica exactamente lo que CreateBookingCommand/RescheduleBookingCommand
 * entregan a BookingScheduler en producción.
 */
function schedulerNormalizedInstant(string $localDateTime, string $timezone): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, $timezone)->setTimezone(config('app.timezone'));
}

test('Ejemplo obligatorio: Asia/Tokyo, Resource 09:00-17:00, reserva 09:00 Tokyo se crea y persiste el mismo instante', function () {
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $localInstant = CarbonImmutable::parse('2026-09-07 09:00:00', 'Asia/Tokyo');
    $startsAt = $localInstant->setTimezone(config('app.timezone'));

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect($booking->exists)->toBeTrue();
    // Mismo instante real, sin importar en qué timezone se compare.
    expect($booking->starts_at->equalTo($localInstant))->toBeTrue();
    expect($booking->starts_at->setTimezone('Asia/Tokyo')->format('H:i'))->toBe('09:00');
});

test('America/New_York, Resource 09:00-17:00, reserva válida se crea', function () {
    $f = schedulerFixturesWithOrgTimezone('America/New_York', '09:00', '17:00');
    $startsAt = schedulerNormalizedInstant('2026-09-07 09:00:00', 'America/New_York');

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect($booking->exists)->toBeTrue();
    expect($booking->starts_at->setTimezone('America/New_York')->format('H:i'))->toBe('09:00');
});

test('fuera del horario local de Organization sigue rechazándose correctamente (el fix no vuelve todo disponible)', function () {
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $outsideLocalHours = schedulerNormalizedInstant('2026-09-07 20:00:00', 'Asia/Tokyo'); // 20:00 Tokio, fuera de 09-17

    expect(fn () => schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $outsideLocalHours, $f['resource']))
        ->toThrow(SlotNoLongerAvailableException::class);
});

test('Organization cuya fecha local difiere de la fecha de config(app.timezone): se valida contra el día local de Organization', function () {
    // 10:00 Asia/Tokyo del 07/09, normalizado a config('app.timezone')
    // (America/Bogota, 14h detrás), cae el 06/09 20:00 — un día calendario
    // ANTERIOR al de Organization. Si BookingScheduler recalculara la
    // ventana de horario usando el día de Bogota (06/09) en vez del de
    // Organization (07/09), podría aplicar un ResourceSchedule de un
    // weekday distinto (o ninguno) — este test prueba que el día que
    // importa es el de Organization.
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $localInstant = CarbonImmutable::parse('2026-09-07 10:00:00', 'Asia/Tokyo');
    $startsAt = $localInstant->setTimezone(config('app.timezone'));

    // Confirma la premisa del caso: normalizado a Bogota, esto cae en el
    // día CALENDARIO anterior al de Organization.
    expect($startsAt->toDateString())->not->toBe($localInstant->toDateString());

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect($booking->exists)->toBeTrue();
    expect($booking->starts_at->equalTo($localInstant))->toBeTrue();
});

test('reprogramación con timezone no-default y Resource de horario acotado funciona', function () {
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $originalStart = schedulerNormalizedInstant('2026-09-07 09:00:00', 'Asia/Tokyo');
    $newLocalStart = CarbonImmutable::parse('2026-09-07 11:00:00', 'Asia/Tokyo');
    $newStart = $newLocalStart->setTimezone(config('app.timezone'));
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $originalStart, $f['resource']);

    $rescheduled = schedulerFor()->reschedule($booking, $newStart);

    expect($rescheduled->starts_at->equalTo($newLocalStart))->toBeTrue();
    expect($rescheduled->starts_at->setTimezone('Asia/Tokyo')->format('H:i'))->toBe('11:00');
});

test('reprogramar fuera del horario local de Organization sigue rechazándose', function () {
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $originalStart = schedulerNormalizedInstant('2026-09-07 09:00:00', 'Asia/Tokyo');
    $outsideLocalHours = schedulerNormalizedInstant('2026-09-07 20:00:00', 'Asia/Tokyo');
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $originalStart, $f['resource']);

    expect(fn () => schedulerFor()->reschedule($booking, $outsideLocalHours))
        ->toThrow(SlotNoLongerAvailableException::class);
});

test('caso límite cercano a medianoche local de Organization: el último slot válido del día se acepta', function () {
    // Horario 23:00-23:59 local de Organization, servicio de 30 min — un
    // único slot posible, 23:00-23:30, deliberadamente pegado a la
    // medianoche para forzar el cruce de día calendario al normalizar.
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '23:00', '23:59');
    $localInstant = CarbonImmutable::parse('2026-09-07 23:00:00', 'Asia/Tokyo');
    $startsAt = $localInstant->setTimezone(config('app.timezone'));

    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    expect($booking->exists)->toBeTrue();
    expect($booking->starts_at->equalTo($localInstant))->toBeTrue();
});

test('AvailabilityCalculator::availableSlots() sigue generando los slots en timezone de Organization, sin cambios', function () {
    $f = schedulerFixturesWithOrgTimezone('Asia/Tokyo', '09:00', '17:00');
    $localDate = CarbonImmutable::parse('2026-09-07 00:00:00', 'Asia/Tokyo');

    $slots = (new AvailabilityCalculator)->availableSlots($f['svc'], $f['location'], $localDate, $f['resource']);

    expect($slots->first()->range->start->format('H:i'))->toBe('09:00');
    expect($slots->first()->range->start->timezoneName)->toBe('Asia/Tokyo');
    expect($slots->last()->range->end->format('H:i'))->toBe('17:00');
});

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

test('cancelar una reserva confirmada la pasa a CANCELLED, registra el motivo y dispara BookingCancelled', function () {
    Event::fake([BookingCancelled::class]);
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);

    $cancelled = schedulerFor()->cancel($booking, 'El cliente ya no puede asistir');

    expect($cancelled->status)->toBe(BookingStatus::CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('El cliente ya no puede asistir');
    expect($cancelled->cancelled_at)->not->toBeNull();
    Event::assertDispatched(BookingCancelled::class, fn ($event) => $event->booking->is($booking));
});

test('cancelar el horario libera el slot para una reserva nueva en el mismo momento', function () {
    $f = schedulerFixtures();
    $startsAt = schedulerDate()->setTime(10, 0);
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);

    schedulerFor()->cancel($booking);

    $second = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $startsAt, $f['resource']);
    expect($second->exists)->toBeTrue();
});

test('cancelar una reserva ya cancelada lanza BookingAlreadyTerminalException', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->cancel($booking);

    expect(fn () => schedulerFor()->cancel($booking->fresh()))->toThrow(BookingAlreadyTerminalException::class);
});

test('reprogramar mueve la reserva al nuevo horario, mantiene el mismo id y dispara BookingRescheduled con la fecha anterior', function () {
    Event::fake([BookingRescheduled::class]);
    $f = schedulerFixtures();
    $originalStart = schedulerDate()->setTime(10, 0);
    $newStart = schedulerDate()->setTime(11, 0);
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $originalStart, $f['resource']);

    $rescheduled = schedulerFor()->reschedule($booking, $newStart);

    expect($rescheduled->id)->toBe($booking->id);
    expect($rescheduled->starts_at->format('H:i'))->toBe('11:00');
    expect($rescheduled->ends_at->format('H:i'))->toBe('11:30');
    expect($rescheduled->status)->toBe(BookingStatus::CONFIRMED);
    Event::assertDispatched(BookingRescheduled::class, function ($event) use ($booking, $originalStart) {
        return $event->booking->is($booking) && $event->previousStartsAt->equalTo($originalStart);
    });
});

test('reprogramar a un horario ya ocupado por otra reserva lanza SlotNoLongerAvailableException y no mueve nada', function () {
    $f = schedulerFixtures(resourceCount: 1);
    $firstStart = schedulerDate()->setTime(10, 0);
    $secondStart = schedulerDate()->setTime(10, 30);
    $first = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $firstStart, $f['resource']);
    schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], $secondStart, $f['resource']);

    expect(fn () => schedulerFor()->reschedule($first, $secondStart))
        ->toThrow(SlotNoLongerAvailableException::class);

    expect($first->fresh()->starts_at->format('H:i'))->toBe('10:00');
});

test('reprogramar una reserva cancelada lanza BookingAlreadyTerminalException', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->cancel($booking);

    expect(fn () => schedulerFor()->reschedule($booking->fresh(), schedulerDate()->setTime(11, 0)))
        ->toThrow(BookingAlreadyTerminalException::class);
});

test('confirmar una reserva PENDING la pasa a CONFIRMED y dispara BookingConfirmed', function () {
    Event::fake([BookingConfirmed::class]);
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    $booking->update(['status' => BookingStatus::PENDING]);
    Event::fake([BookingConfirmed::class]); // limpia el disparo de schedule() de arriba, solo interesa el de confirm()

    $confirmed = schedulerFor()->confirm($booking->fresh());

    expect($confirmed->status)->toBe(BookingStatus::CONFIRMED);
    Event::assertDispatched(BookingConfirmed::class, fn ($event) => $event->booking->is($booking));
});

test('confirmar una reserva que ya estaba CONFIRMED es idempotente y no vuelve a disparar el evento', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    Event::fake([BookingConfirmed::class]);

    schedulerFor()->confirm($booking->fresh());

    Event::assertNotDispatched(BookingConfirmed::class);
});

test('confirmar una reserva cancelada lanza BookingAlreadyTerminalException', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->cancel($booking);

    expect(fn () => schedulerFor()->confirm($booking->fresh()))
        ->toThrow(BookingAlreadyTerminalException::class);
});

test('completar una reserva CONFIRMED la pasa a COMPLETED', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);

    $completed = schedulerFor()->complete($booking);

    expect($completed->status)->toBe(BookingStatus::COMPLETED);
});

test('completar una reserva cancelada lanza BookingAlreadyTerminalException', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->cancel($booking);

    expect(fn () => schedulerFor()->complete($booking->fresh()))
        ->toThrow(BookingAlreadyTerminalException::class);
});

test('marcar ausente una reserva CONFIRMED la pasa a NO_SHOW', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);

    $marked = schedulerFor()->markNoShow($booking);

    expect($marked->status)->toBe(BookingStatus::NO_SHOW);
});

test('marcar ausente una reserva ya COMPLETED también funciona — es la vía para corregir un auto-completado', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->complete($booking);

    $marked = schedulerFor()->markNoShow($booking->fresh());

    expect($marked->status)->toBe(BookingStatus::NO_SHOW);
});

test('marcar ausente una reserva cancelada lanza BookingAlreadyTerminalException — cancelar y no-show son hechos distintos', function () {
    $f = schedulerFixtures();
    $booking = schedulerFor()->schedule($f['svc'], $f['location'], $f['customer'], schedulerDate()->setTime(10, 0), $f['resource']);
    schedulerFor()->cancel($booking);

    expect(fn () => schedulerFor()->markNoShow($booking->fresh()))
        ->toThrow(BookingAlreadyTerminalException::class);
});
