<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use Carbon\CarbonImmutable;

function cancelCommandFixtureBooking(): App\Domain\Booking\Booking
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

    return App\Domain\Booking\Booking::findOrFail($createResult->bookingId);
}

function cancelCommand(): CancelBookingCommand
{
    return new CancelBookingCommand(new BookingScheduler(new AvailabilityCalculator));
}

test('cancela la reserva y devuelve un resultado plano con el estado final', function () {
    $booking = cancelCommandFixtureBooking();

    $result = cancelCommand()->handle($booking, 'Cliente no puede asistir');

    expect($result->bookingId)->toBe($booking->id);
    expect($result->status)->toBe(BookingStatus::CANCELLED);
    expect($booking->fresh()->cancellation_reason)->toBe('Cliente no puede asistir');
});

test('propaga la excepción de dominio al cancelar una reserva ya terminal', function () {
    $booking = cancelCommandFixtureBooking();
    cancelCommand()->handle($booking);

    expect(fn () => cancelCommand()->handle($booking->fresh()))
        ->toThrow(BookingAlreadyTerminalException::class);
});
