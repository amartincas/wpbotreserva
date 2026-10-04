<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Fase 6 — responde a Intent::RegistroNegocioBloqueado, producido únicamente
 * por el guard de InboundMessageRouter (nunca por contenido del mensaje,
 * mismo criterio que ReservaOGestion/BookingChoiceAgent): un arranque fresco
 * de RegistroNegocio de un owner que ya resuelve a una Organization
 * existente (B5: por owner_phone en el CENTRAL, ya no por el Channel). AgentInterface (no OrganizationlessAgentInterface) a propósito
 * — acá SIEMPRE hay una Organization real, es justamente la condición que
 * produjo este Intent, así que recibirla por el contrato normal es correcto
 * (mismo criterio que AdminCommandAgent/ConfirmacionAsistenciaAgent, un
 * comando determinista de un solo turno).
 *
 * Un solo turno (B3): limpia current_intent al terminar. Sin esto, el
 * Intent quedaba activo y ConversationContinuityStrategy (que corre antes
 * que la IA) volvía a mandar acá cada mensaje siguiente durante todo el
 * TTL de continuidad.
 */
class RegistroNegocioBloqueadoAgent implements AgentInterface
{
    public function __construct(
        private readonly ConversationReplier $replier,
        private readonly BotMessageRepository $botMessages,
        private readonly ConversationSessionRepositoryInterface $sessions,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        try {
            $this->replier->send(
                $session,
                $message->fromPhone,
                $this->botMessages->render('registro.negocio_bloqueado')
                    ?? 'Tu negocio ya está registrado con este número. Si necesitás agregar un servicio, cambiar un horario o hacer otra gestión, contame qué querés hacer.',
            );
        } finally {
            $this->sessions->recordIntent($session, null);
        }
    }
}
