<?php

namespace App\Domain\Booking;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 3 — correlación entre un recordatorio de reserva enviado y la
 * respuesta de asistencia que todavía no llegó. Cubre exclusivamente la
 * espera pasiva inicial (puede durar horas o días) — una vez que el cliente
 * responde "No" y pasa a gestionar la reserva (Cancelar/Modificar), esa
 * conversación activa ya usa ConversationSession/draft normal, nunca esta
 * tabla (ver ConfirmacionAsistenciaAgent).
 *
 * declined_at (nullable): se marca cuando el cliente responde "No", SIN
 * borrar la fila todavía — permite que los listeners de BookingCancelled/
 * BookingRescheduled (Fase 3) sepan que una cancelación o reprogramación
 * posterior está correlacionada con este flujo, sin tener que modificar
 * GestionReservaAgent ni CancelBookingCommand/RescheduleBookingCommand para
 * enterarse. Sin declined_at, esos listeners son no-op (cancelación/
 * reprogramación normal, sin recordatorio de por medio).
 */
#[Fillable(['booking_id', 'template_name', 'expires_at', 'declined_at'])]
class PendingAttendanceConfirmation extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
