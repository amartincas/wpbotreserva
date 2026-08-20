<?php

use App\Application\Booking\ConfirmBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\Booking;
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

function confirmCommandFixtureBooking(): Booking
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

function confirmCommand(): ConfirmBookingCommand
{
    return new ConfirmBookingCommand(new BookingScheduler(new AvailabilityCalculator));
}

test('confirma una reserva PENDING y devuelve un resultado plano', function () {
    $booking = confirmCommandFixtureBooking();
    $booking->update(['status' => BookingStatus::PENDING]);

    $result = confirmCommand()->handle($booking->fresh());

    expect($result->bookingId)->toBe($booking->id);
    expect($result->status)->toBe(BookingStatus::CONFIRMED);
});

test('propaga la excepción de dominio al confirmar una reserva ya terminal', function () {
    $booking = confirmCommandFixtureBooking();
    $booking->update(['status' => BookingStatus::CANCELLED, 'cancelled_at' => now()]);

    expect(fn () => confirmCommand()->handle($booking->fresh()))
        ->toThrow(BookingAlreadyTerminalException::class);
});
