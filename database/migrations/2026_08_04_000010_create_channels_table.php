<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aggregate Root (Parte XVI) — deliberadamente SIN organization_id
        // directo: es la única excepción documentada a la regla de Parte XI
        // punto 5, porque un Channel puede servir a varias organizaciones a
        // la vez por diseño (channel_organization). Nunca le apliques el
        // scope de tenant único.
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // App\Enums\ChannelProvider
            $table->string('channel_type'); // App\Enums\ChannelType
            $table->string('phone_number')->nullable();
            // Identificador estable del proveedor — lo que usa ChannelResolver
            // (Hito 4) para identificar el canal, nunca phone_number.
            $table->string('phone_number_id')->nullable()->unique();
            $table->string('business_account_id')->nullable();
            $table->string('status'); // App\Enums\ChannelStatus
            // Forma interna depende del provider — validada en código vía
            // cast, no en columnas separadas por proveedor (evita el
            // anti-patrón de tabla dispersa de Parte II R1).
            $table->text('credentials')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
