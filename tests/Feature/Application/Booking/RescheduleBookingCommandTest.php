<?php

use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Booking\RescheduleBookingCommand;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;

function rescheduleCommandFixtureBooking(): Booking
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
        'start_time' => '09:00', 'end_time' => '12:00',
    ]);

    $createResult = (new CreateBookingCommand(new BookingScheduler(new AvailabilityCalculator)))->handle(new CreateBookingData(
        organization: $org, location: $location, service: $service,
        customerPhone: '+573001234567', customerName: 'Ana',
        startsAt: CarbonImmutable::parse('2026-09-07 10:00'), resource: $resource,
    ));

    return Booking::findOrFail($createResult->bookingId);
}

function rescheduleCommand(): RescheduleBookingCommand
{
    return new RescheduleBookingCommand(new BookingScheduler(new AvailabilityCalculator));
}

test('reprograma la reserva y el resultado trae la fecha anterior y la nueva', function () {
    $booking = rescheduleCommandFixtureBooking();
    $newStart = CarbonImmutable::parse('2026-09-07 11:00');

    $result = rescheduleCommand()->handle($booking, $newStart);

    expect($result->bookingId)->toBe($booking->id);
    expect($result->startsAt->format('H:i'))->toBe('11:00');
    expect($result->previousStartsAt->format('H:i'))->toBe('10:00');
});

test('propaga la excepción de dominio si el nuevo horario ya no está disponible', function () {
    $booking = rescheduleCommandFixtureBooking();

    expect(fn () => rescheduleCommand()->handle($booking, CarbonImmutable::parse('2026-09-07 20:00')))
        ->toThrow(SlotNoLongerAvailableException::class);
});
