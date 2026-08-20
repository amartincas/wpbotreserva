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
 */
enum Intent: string
{
    case RegistroNegocio = 'registro_negocio';
    case Reserva = 'reserva';
    case GestionReserva = 'gestion_reserva';
    case ReservaOGestion = 'reserva_o_gestion';
    case AdminCommand = 'admin_command';
    case FueraDeAlcance = 'fuera_de_alcance';
}
