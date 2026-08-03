<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            // País propio del local (Parte I §6) — independiente del país
            // "de origen" de la Organization; sin tabla countries todavía.
            $table->string('country_code', 2)->default('CO');
            // Null = hereda el de la Organization (cascada de Parte I §6).
            $table->string('timezone')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
