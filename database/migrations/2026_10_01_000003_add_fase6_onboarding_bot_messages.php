<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6 (simplificación del onboarding): claves de bot_messages para los 2
 * mensajes nuevos — doble registro bloqueado y onboarding expirado. Mismo
 * patrón que las migraciones de bot_messages anteriores (insertOrIgnore).
 * Las preguntas de ciudad/dirección (registro.ciudad/registro.direccion) ya
 * existían desde antes y no cambian de clave ni de placeholders — solo
 * cambió, en código, qué extractor procesa la respuesta.
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
                    'key' => 'registro.negocio_bloqueado',
                    'group' => 'registro',
                    'template' => 'Este negocio ya está registrado. Si necesitás agregar un servicio, cambiar un horario o hacer otra gestión, contame qué querés hacer.',
                    'description' => 'Respuesta cuando un Channel ya registrado intenta arrancar un registro nuevo (Intent::RegistroNegocioBloqueado) — tanto por el guard del Router como, en el caso raro de condición de carrera, por RegistroNegocioAgent al capturar ChannelAlreadyRegisteredException.',
                ],
                [
                    'key' => 'registro.expirado',
                    'group' => 'registro',
                    'template' => 'Tu registro anterior quedó incompleto y expiró. Por seguridad no podemos retomarlo — si querés registrar tu negocio, empecemos de nuevo.',
                    'description' => 'Respuesta de RegistroNegocioExpiradoAgent cuando un onboarding abandonado superó el TTL de continuidad (config("conversations.continuity_ttl_minutes")).',
                ],
            ]
        ));
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', [
            'registro.negocio_bloqueado',
            'registro.expirado',
        ])->delete();
    }
};
