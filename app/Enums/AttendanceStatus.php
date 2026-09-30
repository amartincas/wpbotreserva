<?php

namespace App\Enums;

/**
 * Respuesta del cliente al recordatorio de asistencia (Fase 3) —
 * deliberadamente separado de BookingStatus: CONFIRMED en BookingStatus
 * significa "la reserva existe y está activa" (se asigna al crearla),
 * nunca "el cliente confirmó que va a venir". NULL en Booking.attendance_status
 * significa "todavía sin respuesta definitiva" — no hay un caso PENDING acá.
 */
enum AttendanceStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case DECLINED = 'DECLINED';
}
