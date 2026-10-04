<?php

use App\Application\Conversations\BotMessages\BotMessageRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * B8 — revierte las claves de bot_messages que agregó
 * 2026_09_29_000001_add_resource_contact_phone_bot_messages (la pregunta del
 * teléfono del profesional al dar de alta un recurso): el flujo ya no la
 * hace, va del nombre directo al horario. Sin estas filas el panel de
 * mensajes no muestra textos que nadie usa.
 *
 * Se invalida la caché de BotMessageRepository (una hora) para que la
 * lista de mensajes refleje el cambio de inmediato.
 */
return new class extends Migration
{
    private const KEYS = [
        'recurso.telefono_pregunta',
        'recurso.telefono_invalido',
        'recurso.telefono_obligatorio',
    ];

    public function up(): void
    {
        DB::table('bot_messages')->whereIn('key', self::KEYS)->delete();

        Cache::forget(BotMessageRepository::CACHE_KEY);
    }

    /**
     * Restaura los textos sembrados originalmente (no una edición manual que
     * hubieran tenido antes de borrarse).
     */
    public function down(): void
    {
        $now = now();

        DB::table('bot_messages')->insertOrIgnore(array_map(
            fn (array $row) => $row + ['group' => 'recurso', 'created_at' => $now, 'updated_at' => $now],
            [
                [
                    'key' => 'recurso.telefono_pregunta',
                    'template' => '¿Cuál es el número de WhatsApp de {recurso} para avisarle cuando tenga una reserva?',
                    'description' => 'Pregunta obligatoria tras el nombre de un recurso nuevo, antes del horario. Placeholder: {recurso}.',
                ],
                [
                    'key' => 'recurso.telefono_invalido',
                    'template' => 'No pude reconocer el número de {recurso}. Escribilo con el código de país, por ejemplo +573001234567.',
                    'description' => 'Re-pregunta cuando ContactPhoneFieldExtractor no reconoce el formato. Placeholder: {recurso}.',
                ],
                [
                    'key' => 'recurso.telefono_obligatorio',
                    'template' => 'Necesitamos el número de WhatsApp de {recurso} para poder avisarle cuando tenga una reserva nueva. ¿Cuál es?',
                    'description' => 'Re-pregunta cuando el usuario responde "no" o equivalente — el teléfono es obligatorio, nunca se acepta NULL para un recurso nuevo. Placeholder: {recurso}.',
                ],
            ]
        ));

        Cache::forget(BotMessageRepository::CACHE_KEY);
    }
};
