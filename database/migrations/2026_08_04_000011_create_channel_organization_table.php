<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_organization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['channel_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_organization');
    }
};
