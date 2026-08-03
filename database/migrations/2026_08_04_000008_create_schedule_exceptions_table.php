<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate Root propio (Parte III/V — corrección respecto a la v1):
        // festivos/bloqueos/horarios especiales, aplicables a toda la
        // organización (location_id y resource_id null), a un Location
        // completo (resource_id null), o a un Resource puntual — el más
        // específico gana en el algoritmo de disponibilidad (Hito 2).
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('type'); // App\Enums\ScheduleExceptionType
            $table->boolean('is_available')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'date']);
            $table->index(['location_id', 'date']);
            $table->index(['resource_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
