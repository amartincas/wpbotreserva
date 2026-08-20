<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Limpia el Intent activo y el borrador de la sesión (ResetKeywordStrategy
 * ya validó que había algo que limpiar) y deja la conversación lista para
 * arrancar de cero con el próximo mensaje — nunca decide a qué flujo va
 * ese próximo mensaje, eso lo vuelve a resolver el Router normalmente.
 */
class ConversationResetAgent implements AgentInterface
{
    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->notifications->send($organization, $message->fromPhone, 'Listo, empecemos de nuevo. ¿En qué te ayudo?');
    }
}
