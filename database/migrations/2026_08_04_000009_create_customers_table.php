<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate Root minimalista (Parte III) — reemplaza el string
        // suelto `customer_phone` repetido en el código de turismo. Un
        // mismo teléfono es una identidad distinta por cada Organization.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone'); // E.164, ver Domain\Shared\PhoneNumber
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
