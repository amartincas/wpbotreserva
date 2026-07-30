<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'pipeline_stage')) {
                $table->string('pipeline_stage')->default('NUEVO')->after('status');
            }
            if (!Schema::hasColumn('leads', 'lost_reason')) {
                $table->string('lost_reason')->nullable()->after('pipeline_stage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'lost_reason')) {
                $table->dropColumn('lost_reason');
            }
            if (Schema::hasColumn('leads', 'pipeline_stage')) {
                $table->dropColumn('pipeline_stage');
            }
        });
    }
};
