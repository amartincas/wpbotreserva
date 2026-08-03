<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entidad interna del aggregate Service (Parte III) — qué tipo(s)/
        // cantidad de recursos exige un servicio para prestarse.
        Schema::create('service_resource_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type'); // App\Enums\ResourceType
            // Null = cualquier subtipo de ese resource_type.
            $table->string('subtype')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_resource_requirements');
    }
};
