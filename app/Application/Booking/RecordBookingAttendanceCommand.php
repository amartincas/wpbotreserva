<?php

namespace App\Application\Booking;

use App\Domain\Booking\Booking;
use App\Enums\AttendanceStatus;

/**
 * Único punto de escritura de la respuesta de asistencia del cliente (Fase
 * 3) — usado tanto por ConfirmacionAsistenciaAgent (respuesta "Sí") como
 * por RecordAttendanceDeclineOnBookingCancelled (resultado "No → Cancelar").
 * Sin BookingSchedulerInterface: no toca BookingStatus, es un dato
 * ortogonal (ver App\Enums\AttendanceStatus).
 */
class RecordBookingAttendanceCommand
{
    public function handle(Booking $booking, AttendanceStatus $status): void
    {
        $booking->update([
            'attendance_status' => $status,
            'attendance_responded_at' => now(),
        ]);
    }
}
