<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad mínima de mensajes (post-E2E Fase 1, Hallazgo 5): registro
 * append-only de solo observabilidad — nunca se lee desde lógica de
 * negocio, clasificación, routing ni reservas. El mecanismo de
 * deduplicación real sigue siendo el de Redis en
 * ProcessInboundConversationMessage; esta tabla no lo reemplaza ni lo
 * afecta.
 *
 * channel_id/organization_id nullable con nullOnDelete() (no
 * cascadeOnDelete()) a propósito — si se borra un Channel o una
 * Organization (ej. limpieza de datos de prueba), el rastro de mensajes
 * sobrevive con la referencia en NULL en vez de desaparecer junto con lo
 * que se estaba auditando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('message_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_id', 'customer_phone', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
