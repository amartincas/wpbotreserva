<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Null = recurso flotante/remoto, no atado a un local (Parte III).
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type'); // App\Enums\ResourceType — HUMAN|ASSET
            // Vocabulario libre por ahora, sin tabla resource_subtypes
            // (recortado en Parte XII — un solo recurso en el piloto).
            $table->string('subtype')->nullable();
            $table->string('display_name');
            $table->unsignedInteger('capacity')->default(1);
            $table->string('contact_phone')->nullable();
            // Solo si este recurso humano necesita login al panel (Fase 3).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
