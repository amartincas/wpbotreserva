<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MVP: un owner_phone → una Organization (B1). Es lo que permite resolver
 * la Organization de un owner que escribe al número CENTRAL a partir de su
 * teléfono, sin ambigüedad, y lo que detecta a nivel de base de datos una
 * carrera entre dos registros del mismo owner.
 *
 * owner_phone sigue nullable: MariaDB admite varios NULL bajo un UNIQUE.
 *
 * Si ya existen duplicados, la migración se detiene y los lista en vez de
 * resolverlos — elegir cuál Organization conserva el owner_phone es una
 * decisión de negocio, no algo que una migración pueda adivinar.
 * Verificado antes de crear esta migración: sin duplicados en staging
 * (1 Organization) ni en dev.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('organizations')
            ->whereNotNull('owner_phone')
            ->select('owner_phone', DB::raw('COUNT(*) as total'), DB::raw('GROUP_CONCAT(id ORDER BY id) as organization_ids'))
            ->groupBy('owner_phone')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates
                ->map(fn ($row) => "{$row->owner_phone} → organizations [{$row->organization_ids}]")
                ->implode('; ');

            throw new RuntimeException(
                "No se puede agregar UNIQUE(owner_phone): hay owner_phone duplicados ({$detail}). Resolverlos manualmente antes de migrar."
            );
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->unique('owner_phone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['owner_phone']);
        });
    }
};
