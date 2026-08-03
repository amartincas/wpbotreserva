<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot puro: qué recursos concretos pueden prestar qué servicio
        // (Parte III) — la disponibilidad se resuelve consultando esto, no
        // pertenece "dentro" de Resource ni de Service.
        Schema::create('resource_service', function (Blueprint $table) {
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['resource_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_service');
    }
};
