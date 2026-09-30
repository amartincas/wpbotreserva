<?php

use App\Application\Booking\Listeners\CleanupPendingAttendanceConfirmationOnBookingRescheduled;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

function attendanceCleanupFixtureBooking(): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    return Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30),
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);
}

test('con declined_at marcado, borra la fila pendiente sin tocar attendance_status', function () {
    $booking = attendanceCleanupFixtureBooking();
    $pending = PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => now()->addDay(), 'declined_at' => now(),
    ]);

    (new CleanupPendingAttendanceConfirmationOnBookingRescheduled)->handle(new BookingRescheduled($booking, CarbonImmutable::instance($booking->starts_at)));

    expect(PendingAttendanceConfirmation::whereKey($pending->id)->exists())->toBeFalse();
    expect($booking->fresh()->attendance_status)->toBeNull();
});

test('sin declined_at (reprogramación no correlacionada), es no-op: la fila sigue existiendo', function () {
    $booking = attendanceCleanupFixtureBooking();
    $pending = PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => now()->addDay(),
    ]);

    (new CleanupPendingAttendanceConfirmationOnBookingRescheduled)->handle(new BookingRescheduled($booking, CarbonImmutable::instance($booking->starts_at)));

    expect(PendingAttendanceConfirmation::whereKey($pending->id)->exists())->toBeTrue();
});

test('sin ninguna fila pendiente relacionada, es no-op', function () {
    $booking = attendanceCleanupFixtureBooking();

    (new CleanupPendingAttendanceConfirmationOnBookingRescheduled)->handle(new BookingRescheduled($booking, CarbonImmutable::instance($booking->starts_at)));

    expect($booking->fresh()->attendance_status)->toBeNull();
});
