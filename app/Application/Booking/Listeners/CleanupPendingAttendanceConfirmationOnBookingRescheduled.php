<?php

namespace App\Application\Booking\Listeners;

use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\PendingAttendanceConfirmation;

/**
 * Fase 3 — reacción a BookingRescheduled: si esta reprogramación viene de
 * un "No → Modificar" respondido a un recordatorio de asistencia (fila con
 * declined_at marcado), la limpia — sin tocar attendance_status, que nunca
 * se seteó en este camino (el cliente sí quiere venir, solo cambió cuándo).
 *
 * Una reprogramación normal, sin recordatorio de por medio, no encuentra
 * ninguna fila con declined_at y es no-op.
 *
 * Síncrono (no ShouldQueue), mismo criterio que
 * RecordAttendanceDeclineOnBookingCancelled — trabajo local de BD, sin I/O
 * externo.
 */
class CleanupPendingAttendanceConfirmationOnBookingRescheduled
{
    public function handle(BookingRescheduled $event): void
    {
        PendingAttendanceConfirmation::where('booking_id', $event->booking->id)
            ->whereNotNull('declined_at')
            ->delete();
    }
}
