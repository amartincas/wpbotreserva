<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * FueraDeAlcance del número CENTRAL (B4): quien escribe al CENTRAL es un
 * owner (o quiere serlo), así que el menú es exclusivamente administrativo
 * — nunca "Reservar turno"/"Gestionar reserva": las reservas se hacen por
 * el WhatsApp propio de cada negocio, y el texto se lo dice a quien haya
 * llegado acá buscando reservar.
 *
 * Cada botón vuelve como texto (su id) y lo reconoce una strategy que ya
 * existe, sin agregar ninguna:
 *  - "menu_registro_negocio" → ButtonIntentStrategy → RegistroNegocio.
 *  - "gestionar negocio" → frase exacta de DeterministicBusinessManagementStrategy.
 *  - "que citas tengo hoy" → DeterministicAgendaProfesionalStrategy (B7:
 *    fecha + interrogativo + sustantivo de agenda) → la agenda del día de
 *    toda la Organization, en el formato de Fase 5.
 * Las dos últimas solo matchean para un owner con Organization; para un
 * owner nuevo el Router las vuelve a convertir en este mismo menú.
 *
 * Organizationless (puede no haber Organization) y de un solo turno: limpia
 * current_intent al terminar, igual que OutOfScopeAgent.
 */
class CentralOutOfScopeAgent implements OrganizationlessAgentInterface
{
    public const BUTTONS = [
        ['id' => 'menu_registro_negocio', 'title' => 'Registrar negocio'],
        ['id' => 'gestionar negocio', 'title' => 'Gestionar negocio'],
        ['id' => 'que citas tengo hoy', 'title' => 'Consultar agenda'],
    ];

    public function __construct(
        private readonly ConversationReplier $replier,
        private readonly ConversationSessionRepositoryInterface $sessions,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        try {
            $this->replier->sendButtons(
                $session,
                $message->fromPhone,
                'Hola, soy el asistente de WpbotReserva para dueños de negocios. Si querés reservar un turno, escribile directamente al WhatsApp del negocio. ¿En qué te ayudo?',
                self::BUTTONS,
            );
        } finally {
            $this->sessions->recordIntent($session, null);
        }
    }
}
