<?php

namespace App\Application\Conversations;

use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Organizations\OrganizationResolutionStatus;
use App\Domain\Conversational\Events\InboundMessageRejected;
use App\Domain\Conversational\InboundMessage;

/**
 * Orquestador puro (Parte XIII, validado antes del Hito 4): recibe un
 * mensaje ya normalizado, secuencia las resoluciones de Channel/Organization/
 * ConversationSession, delega la clasificación y la selección del agente, y
 * termina en handle() del Agent — nunca invoca un Application Command
 * directamente ni interpreta contenido del mensaje. Los únicos condicionales
 * acá son guardas de flujo sobre resultados ya resueltos por sus
 * colaboradores, nunca sobre el contenido del mensaje.
 *
 * Asume que ya se serializó el procesamiento de esta conversación (mutex de
 * Redis en el Job que lo invoca, Hito 7) — no adquiere locks acá.
 */
class InboundMessageRouter
{
    public function __construct(
        private readonly ChannelResolverInterface $channels,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly OrganizationResolverInterface $organizations,
        private readonly IntentClassifierInterface $classifier,
        private readonly AgentSelector $agentSelector,
    ) {}

    public function handle(InboundMessage $message): void
    {
        $channel = $this->channels->resolve($message->phoneNumberId);

        if ($channel === null) {
            InboundMessageRejected::dispatch($message, 'channel_not_found');

            return;
        }

        if (! $channel->isActive()) {
            InboundMessageRejected::dispatch($message, 'channel_inactive');

            return;
        }

        $session = $this->sessions->findOrCreateFor($channel, $message->fromPhone);

        $resolution = $this->organizations->resolve($channel, $session);

        if ($resolution->status === OrganizationResolutionStatus::NotFound) {
            InboundMessageRejected::dispatch($message, 'organization_not_found');

            return;
        }

        if ($resolution->status === OrganizationResolutionStatus::PendingDisambiguation) {
            // Desambiguación interactiva real es roadmap (Parte XIV,
            // disparador: segundo piloto activo) — hoy se rechaza de forma
            // segura en vez de proceder con una organización adivinada.
            InboundMessageRejected::dispatch($message, 'organization_pending_disambiguation');

            return;
        }

        $organization = $resolution->organization;
        $this->sessions->attachOrganization($session, $organization);

        $intent = $this->classifier->classify($message, $session);
        $this->sessions->recordIntent($session, $intent);

        $agent = $this->agentSelector->selectFor($intent);

        if ($agent === null) {
            InboundMessageRejected::dispatch($message, 'agent_not_available');

            return;
        }

        $agent->handle($message, $session, $organization);
    }
}
