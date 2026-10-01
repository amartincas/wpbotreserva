<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Fase 6 — responde a Intent::RegistroNegocioBloqueado, producido únicamente
 * por el guard de InboundMessageRouter (nunca por contenido del mensaje,
 * mismo criterio que ReservaOGestion/BookingChoiceAgent): un arranque fresco
 * de RegistroNegocio sobre un Channel que ya resuelve a una Organization
 * existente. AgentInterface (no OrganizationlessAgentInterface) a propósito
 * — acá SIEMPRE hay una Organization real, es justamente la condición que
 * produjo este Intent, así que recibirla por el contrato normal es correcto
 * (mismo criterio que AdminCommandAgent/ConfirmacionAsistenciaAgent, un
 * comando determinista de un solo turno que responde con
 * NotificationSenderInterface::send()).
 */
class RegistroNegocioBloqueadoAgent implements AgentInterface
{
    public function __construct(
        private readonly NotificationSenderInterface $notifications,
        private readonly BotMessageRepository $botMessages,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $this->notifications->send(
            $organization,
            $message->fromPhone,
            $this->botMessages->render('registro.negocio_bloqueado')
                ?? 'Este negocio ya está registrado. Si necesitás agregar un servicio, cambiar un horario o hacer otra gestión, contame qué querés hacer.',
        );
    }
}
