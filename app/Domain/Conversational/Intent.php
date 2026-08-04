<?php

namespace App\Domain\Conversational;

/**
 * Vocabulario cerrado que producen los IntentClassifierStrategy y consume
 * AgentSelector (Hito 4) — nunca un string suelto. Alcance de Incremento 1
 * (Parte XII): solo registro y reserva. GestionReserva/AdminCommand llegan
 * con Incremento 2 como casos nuevos del enum, sin tocar el classifier ni
 * el Router.
 */
enum Intent: string
{
    case RegistroNegocio = 'registro_negocio';
    case Reserva = 'reserva';
    case FueraDeAlcance = 'fuera_de_alcance';
}
