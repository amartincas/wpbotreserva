<?php

namespace App\Application\Conversations;

use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Organizations\OrganizationResolutionStatus;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Conversational\Events\InboundMessageRejected;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

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
 * Excepción acotada (Incremento 2, caso real: un cliente con reservas
 * activas quedó atrapado a mitad de GestionReserva porque la IA nunca
 * llegó a preguntar qué quería): al arrancar una conversación nueva (nunca
 * a mitad de una en curso), si el Intent clasificado es Reserva o
 * GestionReserva y el cliente ya tiene reservas activas, se sustituye por
 * ReservaOGestion — sigue siendo una guarda sobre un resultado ya resuelto
 * por un colaborador (ActiveBookingsFinderInterface), nunca una lectura
 * del contenido del mensaje.
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
        private readonly ActiveBookingsFinderInterface $activeBookings,
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

        // Capturado ANTES de clasificar: si ya había un Intent activo, este
        // mensaje continúa un flujo en curso (o lo hereda por continuidad),
        // nunca lo arranca — la desambiguación de abajo solo aplica a una
        // conversación genuinamente nueva, para no volver a preguntar
        // "¿nueva o gestionar?" en medio de un flujo ya elegido.
        $isFreshFlow = $session->current_intent === null;

        $intent = $this->classifier->classify($message, $session);

        if ($isFreshFlow && $organization !== null && in_array($intent, [Intent::Reserva, Intent::GestionReserva], true)) {
            if ($this->activeBookings->forCustomer($organization, $message->fromPhone)->isNotEmpty()) {
                $intent = Intent::ReservaOGestion;
            }
        }

        $this->sessions->recordIntent($session, $intent);

        $invoker = $this->agentSelector->selectFor($intent, $organization);

        if ($invoker === null) {
            InboundMessageRejected::dispatch($message, 'agent_not_available');

            return;
        }

        $invoker->handle($message, $session);
    }
}
