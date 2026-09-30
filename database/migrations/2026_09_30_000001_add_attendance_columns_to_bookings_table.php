<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (confirmación de asistencia) — respuesta del cliente al
 * recordatorio, explícitamente distinta de BookingStatus (que ya significa
 * "la reserva existe y está activa", nunca "el cliente confirmó que va a
 * venir"). NULL = todavía sin respuesta definitiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('attendance_status')->nullable(); // App\Enums\AttendanceStatus
            $table->timestamp('attendance_responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['attendance_status', 'attendance_responded_at']);
        });
    }
};
