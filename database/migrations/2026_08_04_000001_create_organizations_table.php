<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Defaults Colombia — sin lógica multi-país todavía (Parte VII);
            // el esquema ya está listo para expandir sin migración futura.
            $table->string('timezone')->default('America/Bogota');
            $table->string('locale', 5)->default('es');
            $table->char('currency', 3)->default('COP');
            // Ciclo de vida propio de la organización (Parte X) — pertenece
            // a Tenancy, no a un futuro Billing: puede suspenderse por
            // motivos ajenos a facturación.
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            // Teléfono de quien completó el registro conversacional — lo usa
            // el Router (Hito 4) para distinguir dueño de negocio vs. cliente.
            $table->string('owner_phone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
