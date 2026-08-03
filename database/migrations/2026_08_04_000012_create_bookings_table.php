<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate Root más importante del sistema (Parte III). organization_id
        // directo (no solo derivable vía location/service) por la regla de
        // Parte XI punto 5. `status` es una columna simple encapsulada
        // detrás de Booking::status() — no las 3 tablas de workflow
        // configurable, recortadas del Incremento 1 (Parte XII).
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Snapshot al confirmar (Parte II R6/Parte XI punto 4) — lo único
            // que hoy tiene un consumidor real (el propio cálculo de
            // disponibilidad). precio/moneda se agregan cuando Payments
            // exista y los necesite, no antes.
            $table->unsignedInteger('duration_minutes');
            $table->string('status'); // App\Enums\BookingStatus
            $table->text('notes')->nullable();
            // Snapshot de la política de cancelación vigente al confirmar —
            // se vuelve VO estructurado cuando la cancelación (Hito 2+) la
            // evalúe de verdad (Parte XI punto 4).
            $table->text('cancellation_policy_snapshot')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('created_via')->default('WHATSAPP'); // App\Enums\BookingCreatedVia
            $table->timestamps();

            $table->index(['organization_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
        });

        // Invariante de dominio (Parte III): ends_at siempre posterior a starts_at.
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_time_order CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
