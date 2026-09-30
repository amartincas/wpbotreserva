<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 (agenda conversacional del profesional): claves de bot_messages
 * para las respuestas de AgendaProfesionalAgent. Migración separada,
 * insertOrIgnore, mismo patrón que las 3 migraciones de bot_messages
 * anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('bot_messages')->insertOrIgnore(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            [
                [
                    'key' => 'agenda.cantidad_con_citas',
                    'group' => 'agenda',
                    'template' => 'Tenés {cantidad} {unidad} para {fecha}.',
                    'description' => 'Respuesta a "cuántas citas tengo...". Placeholders: {cantidad}, {unidad} (cita/citas), {fecha}.',
                ],
                [
                    'key' => 'agenda.cantidad_sin_citas',
                    'group' => 'agenda',
                    'template' => 'No tenés citas para {fecha}.',
                    'description' => 'Respuesta cuando no hay reservas para la fecha consultada, en modo cantidad o detalle. Placeholder: {fecha}.',
                ],
                [
                    'key' => 'agenda.detalle_header',
                    'group' => 'agenda',
                    'template' => "Tus citas de {fecha}:\n\n{listado}",
                    'description' => 'Encabezado del listado de citas en modo detalle. Placeholders: {fecha}, {listado}.',
                ],
                [
                    'key' => 'agenda.fecha_invalida',
                    'group' => 'agenda',
                    'template' => 'Esa fecha no es válida.',
                    'description' => 'Forma de fecha reconocida pero calendáricamente inválida (ej. 31/02/2026).',
                ],
                [
                    'key' => 'agenda.fecha_no_reconocida',
                    'group' => 'agenda',
                    'template' => 'No entendí la fecha. Probá con "hoy", "mañana" o una fecha como "15 de octubre".',
                    'description' => 'Salvaguarda defensiva: no debería alcanzarse en operación normal (la Strategy ya garantiza forma reconocible).',
                ],
            ]
        ));
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', [
            'agenda.cantidad_con_citas',
            'agenda.cantidad_sin_citas',
            'agenda.detalle_header',
            'agenda.fecha_invalida',
            'agenda.fecha_no_reconocida',
        ])->delete();
    }
};
