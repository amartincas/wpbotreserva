<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Único Agent real del Hito 4 — cierra el pipeline completo (Router →
 * Classifier → AgentSelector → Agent → Command/NotificationSender) sin
 * necesitar los agentes de negocio, que son Hito 5/6. No invoca ningún
 * Application Command (no hay dominio que mutar acá) — solo
 * NotificationSenderInterface, dentro de su allow-list.
 */
class OutOfScopeAgent implements AgentInterface
{
    public function __construct(private readonly NotificationSenderInterface $notifications) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $this->notifications->send(
            $organization,
            $message->fromPhone,
            'Hola, soy el asistente de WpbotReserva. Puedo ayudarte a agendar un turno o, si sos dueño de un negocio, a registrarlo. ¿En qué te ayudo?'
        );
    }
}
