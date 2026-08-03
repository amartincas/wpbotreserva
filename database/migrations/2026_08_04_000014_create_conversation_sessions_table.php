<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate mínimo del contexto Conversational (Parte XI punto 7 /
        // Parte XII) — solo lo indispensable para que el Router (Hito 4)
        // sepa a qué agente despachar. organization_id nace nullable: la
        // sesión arranca sin resolver hasta que ChannelResolver/
        // OrganizationResolver la completan (Parte XIV/XVI). La memoria de
        // trabajo rica sigue usando el mecanismo de Cache ya existente, no
        // esta tabla (recorte explícito de Parte XII).
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone');
            $table->string('current_agent')->nullable();
            $table->timestamps();

            $table->unique('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
