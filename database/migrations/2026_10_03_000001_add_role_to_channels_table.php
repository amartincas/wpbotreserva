<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separación CENTRAL / BUSINESS (B1): la función del Channel vive en su
 * propia columna, no en channel_type — ChannelType describe el medio
 * (WHATSAPP), ChannelRole describe para qué se usa (App\Enums\ChannelRole).
 *
 * Default BUSINESS a propósito: todo Channel existente hoy está (o puede
 * estar) vinculado a una Organization, que es exactamente el significado
 * de BUSINESS. Ningún Channel pasa a CENTRAL acá — eso es una operación de
 * datos explícita y separada (channels:promote-central, B2/B10), nunca un
 * efecto colateral de una migración de esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('role')->default('BUSINESS')->after('channel_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
