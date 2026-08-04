<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajuste de Hito 4: el unique original en customer_phone (solo) no
        // soportaba que un mismo teléfono conversara con dos Channels
        // distintos (dos negocios con números separados) — contradice el
        // modelo N:N de Channel (Parte I §7/XVI). channel_id pasa a formar
        // parte de la identidad de la sesión.
        //
        // current_agent se renombra a current_intent: lo que persiste acá
        // (Hito 4) es el Intent clasificado para dar continuidad
        // conversacional (Parte XI punto 7), nunca un Agent concreto — el
        // mapeo Intent→Agent es responsabilidad exclusiva de AgentSelector,
        // el classifier jamás conoce agentes.
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->dropUnique(['customer_phone']);
            $table->foreignId('channel_id')->after('id')->constrained()->cascadeOnDelete();
            $table->renameColumn('current_agent', 'current_intent');
        });

        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->unique(['channel_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'customer_phone']);
            $table->dropConstrainedForeignId('channel_id');
            $table->renameColumn('current_intent', 'current_agent');
        });

        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->unique('customer_phone');
        });
    }
};
