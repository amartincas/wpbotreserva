<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'pipeline_stage_changed_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->timestamp('pipeline_stage_changed_at')->nullable()->after('pipeline_stage');
            });
        }

        // Backfill: para leads ya existentes no sabemos cuándo cambió de
        // etapa realmente, así que se usa created_at como punto de partida
        // razonable en vez de dejarlo null (lo que dispararía alertas falsas
        // de "estancado" para leads que en realidad son recientes).
        DB::table('leads')
            ->whereNull('pipeline_stage_changed_at')
            ->update(['pipeline_stage_changed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'pipeline_stage_changed_at')) {
                $table->dropColumn('pipeline_stage_changed_at');
            }
        });
    }
};
