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
 */
enum Intent: string
{
    case RegistroNegocio = 'registro_negocio';
    case Reserva = 'reserva';
    case GestionReserva = 'gestion_reserva';
    case AdminCommand = 'admin_command';
    case FueraDeAlcance = 'fuera_de_alcance';
}
