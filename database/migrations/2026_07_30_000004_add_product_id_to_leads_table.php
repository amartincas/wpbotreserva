<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'product_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('product_service_name')
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'product_id')) {
                $table->dropConstrainedForeignId('product_id');
            }
        });
    }
};
