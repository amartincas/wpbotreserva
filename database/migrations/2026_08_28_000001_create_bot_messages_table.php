<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Textos del bot (registro/gestión de negocio) editables desde el panel de
 * super-admin (Fase 2 del plan de mejoras post-piloto) — hasta acá vivían
 * hardcodeados como strings PHP en RegistroNegocioAgent, GestionNegocioAgent,
 * ServiceResourceSelectionFlow y los extractores de campo. Global (un solo
 * set para todos los negocios, sin multi-tenant), el mismo alcance que
 * whatsapp_platform_settings.
 *
 * Los defaults se siembran ACÁ, dentro de la misma migración
 * (insertOrIgnore), no en un seeder aparte — sin esto, el primer deploy
 * post-migración dejaría la tabla vacía y el bot respondería sin texto para
 * cada mensaje hasta que alguien corriera db:seed a mano. insertOrIgnore
 * además evita que una migración futura que agregue una clave nueva pise una
 * fila que un super-admin ya haya editado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group')->nullable();
            $table->text('template');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('bot_messages')->insertOrIgnore(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            [
                // --- Saludo (Fase 3) ---
                [
                    'key' => 'saludo.primer_mensaje',
                    'group' => 'saludo',
                    'template' => '¡Hola! Soy el asistente de WpbotReserva.',
                    'description' => 'Primer mensaje de cualquier conversación nueva de registro o gestión de negocio, antes de la primera pregunta. Sin placeholders.',
                ],

                // --- Registro de negocio (alta inicial) ---
                [
                    'key' => 'registro.nombre_negocio',
                    'group' => 'registro',
                    'template' => '¿Cuál es el nombre de tu negocio?',
                    'description' => 'Primera pregunta del registro. Sin placeholders.',
                ],
                [
                    'key' => 'registro.ciudad',
                    'group' => 'registro',
                    'template' => '¿En qué ciudad está?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'registro.direccion',
                    'group' => 'registro',
                    'template' => '¿Cuál es la dirección?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'registro.confirmar_nombre',
                    'group' => 'registro',
                    'template' => 'Tu negocio se llama *{nombre}*, ¿verdad?',
                    'description' => 'Confirmación cuando el nombre del negocio es de una sola palabra. Placeholder: {nombre}.',
                ],
                [
                    'key' => 'registro.pedir_servicio',
                    'group' => 'registro',
                    'template' => '¿Qué servicio ofrecés? (contame uno a la vez)',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'registro.otro_servicio',
                    'group' => 'registro',
                    'template' => '¿Agregás otro servicio?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'registro.nombre_servicio',
                    'group' => 'registro',
                    'template' => '¿Cuál es el nombre del servicio?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'registro.confirmar_alta',
                    'group' => 'registro',
                    'template' => '¿Confirmás crear tu negocio con estos datos?',
                    'description' => 'Re-pregunta cuando la respuesta de confirmación final no fue clara. Sin placeholders.',
                ],
                [
                    'key' => 'registro.resumen',
                    'group' => 'registro',
                    'template' => <<<'TEXT'
                        Confirmá que estos datos son correctos:

                        Negocio: {negocio}
                        Ciudad: {ciudad}
                        Dirección: {direccion}

                        Servicios:
                        {servicios}

                        Atienden:
                        {recursos}

                        ¿Confirmás?
                        TEXT,
                    'description' => 'Resumen final antes de confirmar el alta. Placeholders: {negocio}, {ciudad}, {direccion}, {servicios} (lista ya formada), {recursos} (lista ya formada).',
                ],
                [
                    'key' => 'registro.listo',
                    'group' => 'registro',
                    'template' => '¡Listo! «{negocio}» quedó registrado. Ya podés recibir reservas por acá.',
                    'description' => 'Placeholder: {negocio}.',
                ],

                // --- Gestión de negocio ya registrado ---
                [
                    'key' => 'gestion.que_hacer',
                    'group' => 'gestion',
                    'template' => '¿Qué querés hacer?',
                    'description' => 'Botones Agregar servicio / Cambiar horario. Sin placeholders.',
                ],
                [
                    'key' => 'gestion.que_hacer_reintento',
                    'group' => 'gestion',
                    'template' => 'No entendí. ¿Qué querés hacer?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'gestion.nombre_servicio_nuevo',
                    'group' => 'gestion',
                    'template' => '¿Cuál es el nombre del servicio nuevo?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'gestion.listo_servicio',
                    'group' => 'gestion',
                    'template' => '¡Listo! Agregué *{servicio}* a tu negocio.',
                    'description' => 'Placeholder: {servicio}.',
                ],
                [
                    'key' => 'gestion.no_agregue_nada',
                    'group' => 'gestion',
                    'template' => 'Ok, no agregué nada.',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'gestion.confirmar_servicio',
                    'group' => 'gestion',
                    'template' => 'Agrego el servicio *{servicio}* ({duracion} min), a cargo de: {recursos}. ¿Confirmás?',
                    'description' => 'Placeholders: {servicio}, {duracion}, {recursos}.',
                ],
                [
                    'key' => 'gestion.a_quien_cambia_horario',
                    'group' => 'gestion',
                    'template' => "¿A quién le cambiás el horario?\n\n{opciones}\n\nRespondé con el número.",
                    'description' => 'Menú numerado cuando hay más de un recurso. Placeholder: {opciones} (lista ya formada).',
                ],
                [
                    'key' => 'gestion.opcion_recurso_invalida',
                    'group' => 'gestion',
                    'template' => 'No entendí la opción. Respondé con el número de la persona o recurso.',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'gestion.nuevo_horario_pregunta',
                    'group' => 'gestion',
                    'template' => "¿Qué días y en qué horario atiende ahora {recurso}? (ej: \"Lunes a Viernes de 9 a 17\")\n\nEsto va a reemplazar el horario actual completo.",
                    'description' => 'Placeholder: {recurso}.',
                ],
                [
                    'key' => 'gestion.confirmar_nuevo_horario',
                    'group' => 'gestion',
                    'template' => "Nuevo horario de *{recurso}*: {horario}.\n\nEsto reemplaza el horario actual. ¿Confirmás?",
                    'description' => 'Placeholders: {recurso}, {horario} (lista ya formada).',
                ],
                [
                    'key' => 'gestion.listo_horario',
                    'group' => 'gestion',
                    'template' => '¡Listo! Actualicé el horario de *{recurso}*.',
                    'description' => 'Placeholder: {recurso}.',
                ],
                [
                    'key' => 'gestion.no_cambie_horario',
                    'group' => 'gestion',
                    'template' => 'Ok, no cambié el horario.',
                    'description' => 'Sin placeholders.',
                ],

                // --- Compartidos entre registro y gestión ---
                [
                    'key' => 'servicio.duracion',
                    'group' => 'servicio',
                    'template' => '¿Cuánto dura {servicio}, en minutos?',
                    'description' => 'Usado al dar de alta un servicio, tanto en el registro inicial como al agregar uno a un negocio existente. Placeholder: {servicio}.',
                ],
                [
                    'key' => 'recurso.quien_presta',
                    'group' => 'recurso',
                    'template' => "¿Quién va a prestar el servicio *{servicio}*?\n\n{opciones}\n0) Agregar una persona nueva\n\nRespondé con el número.",
                    'description' => 'Menú numerado de recursos ya existentes para elegir quién presta un servicio. Placeholders: {servicio}, {opciones} (lista ya formada).',
                ],
                [
                    'key' => 'recurso.primera_persona',
                    'group' => 'recurso',
                    'template' => '¿Cómo se llama la persona o recurso que va a prestar este servicio?',
                    'description' => 'Cuando todavía no hay ningún recurso cargado para elegir. Sin placeholders.',
                ],
                [
                    'key' => 'recurso.persona_nueva',
                    'group' => 'recurso',
                    'template' => '¿Cómo se llama la persona o recurso nueva?',
                    'description' => 'Cuando se elige "0) Agregar una persona nueva" del menú. Sin placeholders.',
                ],
                [
                    'key' => 'recurso.opcion_invalida',
                    'group' => 'recurso',
                    'template' => 'No entendí la opción. Respondé con el número de la persona o recurso, o 0 para agregar una nueva.',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'recurso.horario_pregunta',
                    'group' => 'recurso',
                    'template' => '¿Qué días y en qué horario atiende {recurso}? (ej: "Lunes a Viernes de 9 a 17")',
                    'description' => 'Placeholder: {recurso}.',
                ],
                [
                    'key' => 'recurso.otra_persona',
                    'group' => 'recurso',
                    'template' => '¿Agregás otra persona o recurso para este servicio?',
                    'description' => 'Sin placeholders.',
                ],

                // --- Extractores (mensajes de reintento/error) ---
                [
                    'key' => 'extractor.fallo_ia',
                    'group' => 'extractor',
                    'template' => 'No pude procesar tu respuesta para {campo}. ¿Podés intentarlo de nuevo?',
                    'description' => 'Cuando la llamada a la IA falla (error/timeout) al interpretar un campo simple. Placeholder: {campo}.',
                ],
                [
                    'key' => 'extractor.no_entendido',
                    'group' => 'extractor',
                    'template' => 'No entendí tu respuesta para {campo}. ¿Podés ser más específico?',
                    'description' => 'Cuando la IA no logra interpretar un campo simple. Placeholder: {campo}.',
                ],
                [
                    'key' => 'horario.no_entendido',
                    'group' => 'extractor',
                    'template' => 'No entendí el horario. ¿Podés escribirlo de nuevo? (ej: "Lunes a Viernes de 9 a 17")',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'fecha.no_entendida',
                    'group' => 'extractor',
                    'template' => 'No entendí la fecha. ¿Podés decirme para qué día querés el turno?',
                    'description' => 'Sin placeholders.',
                ],
                [
                    'key' => 'fecha.ambigua_mes',
                    'group' => 'extractor',
                    'template' => '¿De qué mes? Por ejemplo: "24 de agosto".',
                    'description' => 'Cuando se da solo un número de día sin mes. Sin placeholders.',
                ],
                [
                    'key' => 'fecha.ya_paso',
                    'group' => 'extractor',
                    'template' => 'Esa fecha ya pasó. ¿Para qué día querés el turno?',
                    'description' => 'Sin placeholders.',
                ],
            ]
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_messages');
    }
};
