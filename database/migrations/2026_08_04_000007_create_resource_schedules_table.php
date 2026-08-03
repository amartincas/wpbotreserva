<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Horario semanal recurrente — de un Resource propio, o el horario
        // general de un Location que los recursos sin horario propio heredan
        // (cascada de Parte I §8). Exactamente uno de los dos, nunca ambos
        // ni ninguno — mismo patrón dual que schedule_exceptions.
        Schema::create('resource_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 (domingo) .. 6 (sábado)
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['resource_id', 'weekday']);
            $table->index(['location_id', 'weekday']);
        });

        DB::statement('ALTER TABLE resource_schedules ADD CONSTRAINT chk_resource_schedules_time_order CHECK (end_time > start_time)');
        DB::statement('ALTER TABLE resource_schedules ADD CONSTRAINT chk_resource_schedules_owner CHECK ((resource_id IS NULL) != (location_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_schedules');
    }
};
