<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2A (captura del teléfono del profesional): nuevas claves de
 * bot_messages para la pregunta de contact_phone al dar de alta un recurso
 * nuevo (registro inicial y gestión posterior, mismo criterio que
 * recurso.horario_pregunta) y los 2 mensajes de re-pregunta de
 * ContactPhoneFieldExtractor (inválido/ambiguo, y rechazo explícito — el
 * dato es obligatorio, así que ninguno de los dos avanza el flujo).
 * Migración separada, insertOrIgnore, mismo patrón que las 2 migraciones de
 * bot_messages anteriores.
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
                    'key' => 'recurso.telefono_pregunta',
                    'group' => 'recurso',
                    'template' => '¿Cuál es el número de WhatsApp de {recurso} para avisarle cuando tenga una reserva?',
                    'description' => 'Pregunta obligatoria tras el nombre de un recurso nuevo, antes del horario. Placeholder: {recurso}.',
                ],
                [
                    'key' => 'recurso.telefono_invalido',
                    'group' => 'recurso',
                    'template' => 'No pude reconocer el número de {recurso}. Escribilo con el código de país, por ejemplo +573001234567.',
                    'description' => 'Re-pregunta cuando ContactPhoneFieldExtractor no reconoce el formato. Placeholder: {recurso}.',
                ],
                [
                    'key' => 'recurso.telefono_obligatorio',
                    'group' => 'recurso',
                    'template' => 'Necesitamos el número de WhatsApp de {recurso} para poder avisarle cuando tenga una reserva nueva. ¿Cuál es?',
                    'description' => 'Re-pregunta cuando el usuario responde "no" o equivalente — el teléfono es obligatorio, nunca se acepta NULL para un recurso nuevo. Placeholder: {recurso}.',
                ],
            ]
        ));
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', [
            'recurso.telefono_pregunta',
            'recurso.telefono_invalido',
            'recurso.telefono_obligatorio',
        ])->delete();
    }
};
