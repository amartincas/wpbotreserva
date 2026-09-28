<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 (información general del negocio): nuevas claves de bot_messages
 * para la pregunta de descripción del negocio, descripción/precio por
 * servicio (compartidas entre registro y gestión, mismo criterio que
 * servicio.duracion) y las respuestas fijas de InfoNegocioAgent cuando no
 * hay dato para responder. Migración separada de
 * 2026_08_28_000001_create_bot_messages_table — no se modifica la original,
 * insertOrIgnore evita pisar ediciones ya hechas desde Filament.
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
                    'key' => 'registro.descripcion_negocio',
                    'group' => 'registro',
                    'template' => 'Contame brevemente de qué se trata tu negocio (opcional, escribí "no" para omitir).',
                    'description' => 'Pregunta opcional al final de los datos generales del negocio, durante el registro inicial. Sin placeholders.',
                ],
                [
                    'key' => 'servicio.descripcion',
                    'group' => 'servicio',
                    'template' => 'Contame brevemente en qué consiste {servicio} (opcional, escribí "no" para omitir).',
                    'description' => 'Pregunta opcional al dar de alta un servicio, tanto en el registro inicial como al agregar uno a un negocio existente. Placeholder: {servicio}.',
                ],
                [
                    'key' => 'servicio.precio',
                    'group' => 'servicio',
                    'template' => '¿Cuánto cuesta {servicio}? (opcional, escribí "no" para omitir).',
                    'description' => 'Pregunta opcional de precio al dar de alta un servicio. Acepta un número o una condición en texto libre (ej. "depende de la consulta"). Placeholder: {servicio}.',
                ],
                [
                    'key' => 'info_negocio.sin_datos',
                    'group' => 'info_negocio',
                    'template' => 'No tengo esa información todavía. Te recomiendo consultarlo directamente con el negocio.',
                    'description' => 'Respuesta fija cuando InfoNegocioAgent no encuentra el dato pedido en el contexto del negocio (sentinel SIN_INFORMACION). Sin placeholders.',
                ],
                [
                    'key' => 'info_negocio.precio_no_registrado',
                    'group' => 'info_negocio',
                    'template' => 'Todavía no tengo el precio de ese servicio cargado. Te recomiendo consultarlo directamente con el negocio.',
                    'description' => 'Respuesta fija cuando preguntan el precio de un servicio que existe pero no tiene precio registrado (sentinel PRECIO_NO_REGISTRADO). Sin placeholders.',
                ],
            ]
        ));
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', [
            'registro.descripcion_negocio',
            'servicio.descripcion',
            'servicio.precio',
            'info_negocio.sin_datos',
            'info_negocio.precio_no_registrado',
        ])->delete();
    }
};
