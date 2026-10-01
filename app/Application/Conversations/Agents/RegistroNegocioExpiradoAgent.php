<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Fase 6 — responde a Intent::RegistroNegocioExpirado, producido únicamente
 * por ExpiredRegistroNegocioStrategy (nunca por contenido del mensaje).
 * OrganizationlessAgentInterface (no AgentInterface): en este punto nunca
 * existe una Organization — si existiera, current_intent jamás habría sido
 * RegistroNegocio para empezar. Mismo motivo por el que RegistroNegocioAgent
 * usa ChannelClientInterface directo en vez de NotificationSenderInterface
 * (que resuelve el Channel A TRAVÉS de una Organization que acá tampoco
 * existe).
 *
 * Responsabilidad única: avisar, y dejar la sesión genuinamente limpia
 * (draft + current_intent) para que el PRÓXIMO mensaje arranque un registro
 * de cero, nunca una continuación fingida de uno vencido. No dispara el
 * nuevo registro automáticamente — el propio mensaje del usuario después de
 * este aviso ya pasa por clasificación normal, sin estado previo que lo
 * condicione.
 */
class RegistroNegocioExpiradoAgent implements OrganizationlessAgentInterface
{
    public function __construct(
        private readonly ChannelClientInterface $channelClient,
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly BotMessageRepository $botMessages,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->channelClient->sendTextMessage(
            $session->channel,
            $session->customer_phone->value(),
            $this->botMessages->render('registro.expirado')
                ?? 'Tu registro anterior quedó incompleto y expiró. Por seguridad no podemos retomarlo — si querés registrar tu negocio, empecemos de nuevo.',
        );
    }
}
