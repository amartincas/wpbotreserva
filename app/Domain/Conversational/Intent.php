<?php

namespace App\Domain\Conversational;

/**
 * Vocabulario cerrado que producen los IntentClassifierStrategy y consume
 * AgentSelector (Hito 4) — nunca un string suelto.
 *
 * AdminCommand (Incremento 2) es deliberadamente uno solo, no uno por verbo
 * ("reservas hoy" / "cancelar N" / "confirmar N") — el Intent identifica
 * QUÉ AGENTE atiende el mensaje, no qué acción puntual ejecuta; el verbo
 * exacto lo vuelve a parsear AdminCommandAgent (mismo criterio que evitó
 * inflar este enum con casos que solo le importan a un único Agent).
 *
 * ReservaOGestion nunca lo produce el classifier — InboundMessageRouter lo
 * sustituye por encima de Reserva/GestionReserva cuando el cliente ya
 * tiene reservas activas y arranca una conversación nueva (no continúa una
 * en curso): en vez de que la IA adivine cuál de las dos quiso decir,
 * BookingChoiceAgent se lo pregunta directo. Ver InboundMessageRouter.
 *
 * Reset (caso real: alguien elige mal en BookingChoiceAgent, o cambia de
 * opinión a mitad de cualquier flujo, y queda sin forma de salir) — lo
 * produce ResetKeywordStrategy, nunca la IA, ante una palabra exacta tipo
 * "salir" mientras hay un Intent activo. ConversationResetAgent limpia
 * todo y deja la próxima conversación arrancar de cero.
 *
 * GestionNegocio (Incremento 4, puntos D/E de la prueba real: el dueño de
 * un negocio ya registrado quiso agregar un servicio y cambiar un horario,
 * y no había ningún flujo para eso) — lo produce
 * DeterministicBusinessManagementStrategy ante frases exactas del dueño
 * ("agregar servicio", "cambiar horario", etc.), nunca la IA: mismo
 * criterio que AdminCommand, es una acción sensible de un único dueño, no
 * algo que valga arriesgar a una clasificación ambigua.
 *
 * InfoNegocio (Fase 1, información general del negocio): preguntas abiertas
 * sobre el negocio (qué hace, dónde queda, cuánto cuesta un servicio) que
 * antes caían todas a FueraDeAlcance por no existir ningún Agent que
 * respondiera con datos reales del negocio. Sí lo produce la IA
 * (AiIntentClassifierStrategy) — a diferencia de GestionNegocio/AdminCommand,
 * acá no hay frases gatillo fijas: es lenguaje abierto por diseño.
 *
 * ConfirmacionAsistencia (Fase 3): respuesta del cliente a un recordatorio
 * de asistencia ya enviado — la produce PendingAttendanceConfirmationStrategy,
 * nunca la IA (coincidencia exacta de "sí"/"no"/ids de botón, y solo cuando
 * existe una fila pendiente real para ese cliente y esa Organization). El
 * "No" de este flujo entrega el control a GestionReserva sin duplicar su
 * lógica — ver ConfirmacionAsistenciaAgent.
 *
 * AgendaProfesional (Fase 4; B7: del owner): consulta de la agenda de toda
 * la Organization desde el CENTRAL — la produce
 * DeterministicAgendaProfesionalStrategy, nunca la IA. Gatea por
 * Organization.owner_phone (mismo gate que AdminCommand; antes, por
 * el teléfono propio del profesional), siempre scoped a la
 * Organization ya resuelta. Un
 * solo Intent para "cuántas"/"qué" — igual criterio que AdminCommand: el
 * Intent identifica el Agent, no la acción puntual (ver AgendaProfesionalAgent).
 *
 * RegistroNegocioBloqueado (Fase 6): mismo criterio que ReservaOGestion —
 * NINGÚN IntentClassifierStrategy lo produce nunca. Es una sustitución que
 * hace InboundMessageRouter sobre un RegistroNegocio ya clasificado, cuando
 * el owner que escribe al CENTRAL ya resuelve a una Organization (B5: por
 * owner_phone) en un arranque de conversación nuevo (nunca a mitad de un
 * registro ya en curso) — evita que RegistroNegocioAgent corra de nuevo y
 * cree una segunda Organization para el mismo owner. Ver
 * InboundMessageRouter y RegistroNegocioBloqueadoAgent.
 *
 * RegistroNegocioExpirado (Fase 6): sí lo produce un Strategy determinista
 * (ExpiredRegistroNegocioStrategy), pero nunca a partir de analizar el
 * contenido del mensaje — solo del estado ya vencido de la sesión
 * (current_intent todavía RegistroNegocio + TTL de continuidad superado).
 * Informa al dueño que su registro anterior expiró y deja la sesión limpia
 * para un intento nuevo, en vez de dejar que el mensaje se reclasifique en
 * silencio y el registro parezca "continuar" con un draft vacío.
 */
enum Intent: string
{
    case RegistroNegocio = 'registro_negocio';
    case RegistroNegocioBloqueado = 'registro_negocio_bloqueado';
    case RegistroNegocioExpirado = 'registro_negocio_expirado';
    case Reserva = 'reserva';
    case GestionReserva = 'gestion_reserva';
    case ReservaOGestion = 'reserva_o_gestion';
    case Reset = 'reset';
    case AdminCommand = 'admin_command';
    case GestionNegocio = 'gestion_negocio';
    case InfoNegocio = 'info_negocio';
    case ConfirmacionAsistencia = 'confirmacion_asistencia';
    case AgendaProfesional = 'agenda_profesional';
    case FueraDeAlcance = 'fuera_de_alcance';
}
