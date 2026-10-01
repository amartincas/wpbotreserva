<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 (ajuste de presentación de la agenda profesional): agrega el emoji
 * 📅 a los templates de encabezado y "sin citas" — mismas claves sembradas
 * por 2026_09_30_000003_add_agenda_profesional_bot_messages.php, ahora
 * actualizadas (no una clave nueva). El formato de cada línea de cita no
 * vive en bot_messages (se arma en AgendaProfesionalAgent::replyDetail()),
 * así que no hay una tercera clave que tocar acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bot_messages')->where('key', 'agenda.detalle_header')->update([
            'template' => "📅 Tus citas de {fecha}:\n\n{listado}",
            'updated_at' => now(),
        ]);

        DB::table('bot_messages')->where('key', 'agenda.cantidad_sin_citas')->update([
            'template' => '📅 No tenés citas para {fecha}.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('bot_messages')->where('key', 'agenda.detalle_header')->update([
            'template' => "Tus citas de {fecha}:\n\n{listado}",
            'updated_at' => now(),
        ]);

        DB::table('bot_messages')->where('key', 'agenda.cantidad_sin_citas')->update([
            'template' => 'No tenés citas para {fecha}.',
            'updated_at' => now(),
        ]);
    }
};
