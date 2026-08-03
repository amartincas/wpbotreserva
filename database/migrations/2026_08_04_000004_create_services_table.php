<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->unsignedInteger('capacity_per_slot')->default(1);
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // Texto libre por ahora — se vuelve Value Object estructurado
            // recién cuando la cancelación (Hito 2+) necesite evaluarlo
            // (Parte XII).
            $table->text('cancellation_policy')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Invariante de dominio (Parte III): duración > 0.
        DB::statement('ALTER TABLE services ADD CONSTRAINT chk_services_duration_positive CHECK (duration_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
