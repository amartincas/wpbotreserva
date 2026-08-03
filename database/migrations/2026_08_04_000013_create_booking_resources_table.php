<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entidad interna del aggregate Booking (Parte III) — qué recurso(s)
        // concretos quedaron comprometidos en esta reserva. No existe fuera
        // de una Booking, por eso no lleva organization_id propio.
        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fulfills_requirement_id')->nullable()
                ->constrained('service_resource_requirements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['booking_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_resources');
    }
};
