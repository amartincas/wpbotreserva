<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\RecordBookingAttendanceCommand;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Enums\AttendanceStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 3 — reacción a BookingCancelled: si esta cancelación está
 * correlacionada con un "No" respondido a un recordatorio de asistencia
 * (fila PendingAttendanceConfirmation con declined_at marcado), recién acá
 * se persiste DECLINED — nunca antes (ver ConfirmacionAsistenciaAgent, "No"
 * no registra DECLINED por sí solo, para no confundir "quiere reprogramar"
 * con "no va a venir").
 *
 * Una cancelación normal, sin ningún recordatorio de por medio, no
 * encuentra ninguna fila con declined_at y es no-op — nunca toca
 * attendance_status de una reserva que nunca tuvo la pregunta.
 *
 * Síncrono (no ShouldQueue): la cancelación de la reserva es la operación
 * primaria — este listener corre inline dentro del mismo cancel(), sin
 * transacción que lo envuelva (BookingScheduler::cancel() no tiene una).
 * Por eso el try/catch de abajo es obligatorio, no cosmético: sin él, un
 * fallo acá (persistencia SECUNDARIA) se propagaría hasta el caller de
 * CancelBookingCommand y haría parecer fallida una cancelación que en
 * realidad ya quedó persistida como CANCELLED (hallazgo de la revisión
 * pre-commit) — nunca debe pasar, la asistencia es de mejor esfuerzo, la
 * cancelación no.
 */
class RecordAttendanceDeclineOnBookingCancelled
{
    public function __construct(private readonly RecordBookingAttendanceCommand $recordAttendance) {}

    public function handle(BookingCancelled $event): void
    {
        $pending = PendingAttendanceConfirmation::where('booking_id', $event->booking->id)
            ->whereNotNull('declined_at')
            ->first();

        if ($pending === null) {
            return;
        }

        try {
            $this->recordAttendance->handle($event->booking, AttendanceStatus::DECLINED);
            $pending->delete();
        } catch (Throwable $e) {
            Log::error('RecordAttendanceDeclineOnBookingCancelled: falló la persistencia secundaria de asistencia, la cancelación ya quedó aplicada', [
                'booking_id' => $event->booking->id,
                'pending_id' => $pending->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
