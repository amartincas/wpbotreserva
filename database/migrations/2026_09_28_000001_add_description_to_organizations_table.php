<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 (información general del negocio): descripción breve, opcional,
 * que el bot usa como fuente de verdad para responder preguntas abiertas
 * del cliente ("¿qué hacen?") — ver InfoNegocioAgent/BusinessContextBuilder.
 * Nullable, sin default — todas las organizaciones existentes quedan en
 * NULL, sin impacto (BusinessContextBuilder omite la sección si no hay
 * descripción, nunca inventa una).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
