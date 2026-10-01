<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 (protección contra doble registro, capa 2 — defensa transaccional):
 * decisión de producto nueva, "un Channel = máximo una Organization",
 * reemplaza la invariante anterior documentada en Channel.php/Organization.php
 * ("un Channel puede servir a varias organizaciones por diseño", Parte XIV).
 * El UNIQUE(channel_id, organization_id) ya existente (migración
 * 2026_08_04_000011) no alcanza: permite channel_id=10 con organization_id=1
 * Y channel_id=10 con organization_id=2 como dos filas distintas, que es
 * exactamente el agujero que esta constraint cierra. Verificado antes de
 * crear esta migración: sin duplicados de channel_id en channel_organization
 * (dev ni test), así que no hace falta ninguna limpieza de datos previa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_organization', function (Blueprint $table) {
            $table->unique('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('channel_organization', function (Blueprint $table) {
            $table->dropUnique(['channel_id']);
        });
    }
};
