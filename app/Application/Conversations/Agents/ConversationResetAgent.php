<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Limpia el Intent activo y el borrador de la sesión (ResetKeywordStrategy
 * ya validó que había algo que limpiar) y deja la conversación lista para
 * arrancar de cero con el próximo mensaje — nunca decide a qué flujo va
 * ese próximo mensaje, eso lo vuelve a resolver el Router normalmente.
 *
 * OrganizationlessAgentInterface (B3): limpiar el estado conversacional no
 * depende de que exista una Organization. Antes era un AgentInterface, así
 * que un "salir" a mitad de un onboarding (sin Organization todavía) no
 * encontraba invoker: el mensaje se rechazaba sin respuesta y el Intent
 * `reset` ya registrado por el Router quedaba atrapando la sesión.
 */
class ConversationResetAgent implements OrganizationlessAgentInterface
{
    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly ConversationReplier $replier,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->replier->send($session, $message->fromPhone, 'Listo, empecemos de nuevo. ¿En qué te ayudo?');
    }
}
