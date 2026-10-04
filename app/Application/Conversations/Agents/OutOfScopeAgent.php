<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Único Agent real del Hito 4 — cierra el pipeline completo (Router →
 * Classifier → AgentSelector → Agent) sin necesitar los agentes de
 * negocio. No invoca ningún Application Command (no hay dominio que mutar
 * acá) — solo responde.
 *
 * B3: OrganizationlessAgentInterface — el menú no usa la Organization, y
 * un mensaje fuera de alcance de alguien sin Organization resuelta (un
 * owner nuevo, antes de registrarse) tiene que recibir este menú igual, no
 * un rechazo silencioso. Un solo turno: limpia current_intent al terminar,
 * para que el próximo mensaje se clasifique de nuevo en vez de quedar
 * atrapado en FueraDeAlcance por continuidad.
 */
class OutOfScopeAgent implements OrganizationlessAgentInterface
{
    public function __construct(
        private readonly ConversationReplier $replier,
        private readonly ConversationSessionRepositoryInterface $sessions,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        try {
            // Botones en vez de pedirle al cliente que lo escriba: caso real
            // (Incremento 4) donde el clasificador de IA falló varias veces
            // seguidas ante frases inequívocas como "quiero registrar mi
            // negocio" — con un conjunto cerrado de 3 opciones, ButtonIntentStrategy
            // reconoce la respuesta con coincidencia exacta, sin volver a pasar
            // por la IA.
            $this->replier->sendButtons(
                $session,
                $message->fromPhone,
                'Hola, soy el asistente de WpbotReserva. ¿En qué te ayudo?',
                [
                    ['id' => 'menu_registro_negocio', 'title' => 'Registrar negocio'],
                    ['id' => 'menu_reserva', 'title' => 'Reservar turno'],
                    ['id' => 'menu_gestion_reserva', 'title' => 'Gestionar reserva'],
                ],
            );
        } finally {
            $this->sessions->recordIntent($session, null);
        }
    }
}
