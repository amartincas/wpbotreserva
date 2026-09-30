<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — correlación entre un recordatorio de asistencia enviado y la
 * respuesta que todavía no llegó (ver App\Domain\Booking\PendingAttendanceConfirmation
 * para el razonamiento completo de declined_at). Entidad interna del
 * aggregate Booking, sin organization_id propio — se llega a la
 * Organization vía join, mismo criterio que booking_resources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_attendance_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('template_name');
            $table->timestamp('expires_at');
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_attendance_confirmations');
    }
};
