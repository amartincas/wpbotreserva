<?php

use App\Application\Booking\Listeners\RecordAttendanceDeclineOnBookingCancelled;
use App\Application\Booking\RecordBookingAttendanceCommand;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use Illuminate\Support\Facades\Log;

function attendanceDeclineFixtureBooking(): Booking
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $location = Location::create(['organization_id' => $org->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $org->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $org->id, 'phone' => '+573001234567']);

    return Booking::create([
        'organization_id' => $org->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
        'duration_minutes' => 30, 'status' => BookingStatus::CANCELLED, 'cancelled_at' => now(),
    ]);
}

test('con declined_at marcado, registra DECLINED y borra la fila pendiente', function () {
    $booking = attendanceDeclineFixtureBooking();
    $pending = PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => $booking->starts_at, 'declined_at' => now(),
    ]);

    (new RecordAttendanceDeclineOnBookingCancelled(new RecordBookingAttendanceCommand))->handle(new BookingCancelled($booking));

    $booking = $booking->fresh();
    expect($booking->attendance_status)->toBe(AttendanceStatus::DECLINED);
    expect($booking->attendance_responded_at)->not->toBeNull();
    expect(PendingAttendanceConfirmation::whereKey($pending->id)->exists())->toBeFalse();
});

test('sin declined_at (cancelación no correlacionada), es no-op: no toca attendance_status ni borra la fila', function () {
    $booking = attendanceDeclineFixtureBooking();
    $pending = PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => $booking->starts_at,
    ]);

    (new RecordAttendanceDeclineOnBookingCancelled(new RecordBookingAttendanceCommand))->handle(new BookingCancelled($booking));

    expect($booking->fresh()->attendance_status)->toBeNull();
    expect(PendingAttendanceConfirmation::whereKey($pending->id)->exists())->toBeTrue();
});

test('sin ninguna fila pendiente relacionada, es no-op', function () {
    $booking = attendanceDeclineFixtureBooking();

    (new RecordAttendanceDeclineOnBookingCancelled(new RecordBookingAttendanceCommand))->handle(new BookingCancelled($booking));

    expect($booking->fresh()->attendance_status)->toBeNull();
});

test('si la persistencia secundaria lanza una excepción, NO se propaga al caller y queda logueada con contexto', function () {
    Log::spy();

    $booking = attendanceDeclineFixtureBooking();
    $pending = PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => $booking->starts_at, 'declined_at' => now(),
    ]);

    $throwingCommand = new class extends RecordBookingAttendanceCommand
    {
        public function handle(Booking $booking, AttendanceStatus $status): void
        {
            throw new RuntimeException('fallo simulado de persistencia secundaria');
        }
    };

    // La ausencia de una excepción lanzada hacia afuera de este handle() ES
    // la aserción principal del test — si el listener propagara, Pest
    // fallaría acá mismo con la RuntimeException sin capturar.
    (new RecordAttendanceDeclineOnBookingCancelled($throwingCommand))->handle(new BookingCancelled($booking));

    // La cancelación (ya persistida antes de que este listener corra) no se
    // ve afectada — sigue CANCELLED, y la fila pendiente queda tal cual
    // (ni se borró, porque la excepción interrumpió antes de esa línea).
    expect($booking->fresh()->status)->toBe(BookingStatus::CANCELLED);
    expect($booking->fresh()->attendance_status)->toBeNull();
    expect(PendingAttendanceConfirmation::whereKey($pending->id)->exists())->toBeTrue();

    Log::shouldHaveReceived('error')->once()->with(
        'RecordAttendanceDeclineOnBookingCancelled: falló la persistencia secundaria de asistencia, la cancelación ya quedó aplicada',
        Mockery::on(fn ($context) => $context['booking_id'] === $booking->id
            && $context['pending_id'] === $pending->id
            && str_contains($context['error'], 'fallo simulado de persistencia secundaria')),
    );
});
