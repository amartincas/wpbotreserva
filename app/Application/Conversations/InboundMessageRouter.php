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
 * termina en handle() de un AgentInvoker — nunca invoca un Application
 * Command directamente ni interpreta contenido del mensaje. Los únicos
 * condicionales acá son guardas de flujo sobre resultados ya resueltos por
 * sus colaboradores, nunca sobre el contenido del mensaje.
 *
 * Corrección post-Hito 4 (Hito 5): Unregistered ya no rechaza el mensaje —
 * es el estado inicial normal de todo Channel antes de su primer registro,
 * no un error, y el flujo de alta de negocio (RegistroNegocioAgent) tiene
 * que poder arrancar justo ahí. El Router sigue el mismo camino siempre
 * (clasificar → registrar Intent → seleccionar invoker → delegar) con
 * $organization en null cuando corresponda — nunca sabe que Organization
 * puede faltar ni que existen dos tipos de Agent; esa decisión vive
 * enteramente en AgentSelector.
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

        if ($resolution->status === OrganizationResolutionStatus::PendingDisambiguation) {
            // Desambiguación interactiva real es roadmap (Parte XIV,
            // disparador: segundo piloto activo) — hoy se rechaza de forma
            // segura en vez de proceder con una organización adivinada.
            InboundMessageRejected::dispatch($message, 'organization_pending_disambiguation');

            return;
        }

        $organization = $resolution->status === OrganizationResolutionStatus::Resolved
            ? $resolution->organization
            : null;

        if ($organization !== null) {
            $this->sessions->attachOrganization($session, $organization);
        }

        $intent = $this->classifier->classify($message, $session);
        $this->sessions->recordIntent($session, $intent);

        $invoker = $this->agentSelector->selectFor($intent, $organization);

        if ($invoker === null) {
            InboundMessageRejected::dispatch($message, 'agent_not_available');

            return;
        }

        $invoker->handle($message, $session);
    }
}
