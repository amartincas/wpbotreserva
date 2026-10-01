<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7 (validación de horarios): claves de bot_messages para los 2
 * mensajes nuevos de WeeklyScheduleFieldExtractor::validateSlots() — rango
 * inválido (fin antes que inicio) y franjas solapadas el mismo día. Mismo
 * patrón que las migraciones de bot_messages anteriores (insertOrIgnore).
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
                    'key' => 'horario.rango_invalido',
                    'group' => 'horario',
                    'template' => 'Ese horario no es válido — la hora de fin tiene que ser posterior a la de inicio. ¿Podés escribirlo de nuevo?',
                    'description' => 'WeeklyScheduleFieldExtractor::validateSlots() — un slot con end_time <= start_time (o weekday/hora malformados), venga del parser determinista o de la respuesta de IA.',
                ],
                [
                    'key' => 'horario.solapado',
                    'group' => 'horario',
                    'template' => 'Dos de los horarios que diste se superponen el mismo día. ¿Podés revisarlos y escribirlos de nuevo?',
                    'description' => 'WeeklyScheduleFieldExtractor::validateSlots() — dos franjas del mismo weekday se solapan (intervalo semiabierto, franjas adyacentes sí se permiten).',
                ],
            ]
        ));
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', [
            'horario.rango_invalido',
            'horario.solapado',
        ])->delete();
    }
};
