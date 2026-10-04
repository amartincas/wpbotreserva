<?php

namespace App\Application\Conversations;

use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Organizations\OrganizationResolutionStatus;
use App\Application\Organizations\OwnerOrganizationResolver;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Conversational\Events\InboundMessageRejected;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use Illuminate\Support\Facades\Log;

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
 * B4 — routing por rol del Channel. Primero se resuelve el Channel por su
 * phone_number_id y después se mira su `role` (nunca se deduce el rol de si
 * tiene o no un vínculo en channel_organization):
 *  - CENTRAL: la Organization sale del teléfono de quien escribe
 *    (OwnerOrganizationResolver, owner_phone). Un CENTRAL vinculado a una
 *    Organization es una inconsistencia (solo alcanzable con SQL manual):
 *    se rechaza y se loguea como error, nunca se procesa.
 *  - BUSINESS: la Organization sale del vínculo del Channel
 *    (SingleOrganizationResolver). Sin vínculo → channel_unlinked; un
 *    BUSINESS nunca abre un onboarding.
 * Un resultado Inconsistent de cualquiera de los dos resolvers también se
 * rechaza (fail closed). Después de clasificar y aplicar las sustituciones,
 * AgentSelector::effectiveIntent() convierte un Intent que el rol no atiende
 * en el FueraDeAlcance de ese rol — ANTES de recordIntent(), para que
 * current_intent nunca quede con un Intent inválido para el Channel.
 *
 * Asume que ya se serializó el procesamiento de esta conversación (mutex de
 * Redis en el Job que lo invoca, Hito 7) — no adquiere locks acá.
 */
class InboundMessageRouter
{
    public function __construct(
        private readonly ChannelResolverInterface $channels,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly OrganizationResolverInterface $businessOrganizations,
        private readonly OwnerOrganizationResolver $centralOrganizations,
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

        if ($channel->isCentral() && $channel->organizations()->exists()) {
            Log::error('InboundMessageRouter: el Channel CENTRAL está vinculado a una Organization — inconsistencia, mensaje rechazado', [
                'channel_id' => $channel->id,
                'phone_number_id' => $message->phoneNumberId,
            ]);
            InboundMessageRejected::dispatch($message, 'central_linked');

            return;
        }

        $session = $this->sessions->findOrCreateFor($channel, $message->fromPhone);

        $resolution = $channel->isCentral()
            ? $this->centralOrganizations->resolve($channel, $session)
            : $this->businessOrganizations->resolve($channel, $session);

        if ($resolution->status === OrganizationResolutionStatus::Inconsistent) {
            Log::error('InboundMessageRouter: resolución de Organization inconsistente — mensaje rechazado', [
                'channel_id' => $channel->id,
                'role' => $channel->role->value,
                'reason' => $resolution->reason,
            ]);
            InboundMessageRejected::dispatch($message, $resolution->reason);

            return;
        }

        if ($resolution->status === OrganizationResolutionStatus::Unregistered && $channel->isBusiness()) {
            Log::warning('InboundMessageRouter: Channel BUSINESS sin Organization vinculada — mensaje rechazado', [
                'channel_id' => $channel->id,
                'phone_number_id' => $message->phoneNumberId,
            ]);
            InboundMessageRejected::dispatch($message, 'channel_unlinked');

            return;
        }

        $organization = $resolution->organization;

        if ($organization !== null) {
            $this->sessions->attachOrganization($session, $organization);
        } else {
            $this->sessions->detachOrganization($session);
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

        // Fase 6: mismo patrón que la sustitución de arriba — una guarda
        // sobre un resultado ya resuelto por un colaborador (Organization),
        // nunca sobre el contenido del mensaje. $isFreshFlow es lo que
        // distingue "alguien intenta arrancar un registro nuevo" de "sigue
        // un registro ya en curso" (incluido el caso real de una sesión
        // memoizada a una Organization vieja: ahí current_intent ya era
        // RegistroNegocio antes de este mensaje, así que $isFreshFlow es
        // false y esta guarda no interfiere). Cubre tanto ButtonIntentStrategy
        // (clic en "Registrar negocio") como AiIntentClassifierStrategy
        // (frase libre) por igual, porque actúa sobre el Intent ya
        // clasificado, no sobre cuál estrategia lo produjo.
        if ($isFreshFlow && $organization !== null && $intent === Intent::RegistroNegocio) {
            $intent = Intent::RegistroNegocioBloqueado;
        }

        $intent = $this->agentSelector->effectiveIntent($channel->role, $intent, $organization);

        $this->sessions->recordIntent($session, $intent);

        $invoker = $this->agentSelector->selectFor($channel->role, $intent, $organization);

        if ($invoker === null) {
            InboundMessageRejected::dispatch($message, 'agent_not_available');

            return;
        }

        $invoker->handle($message, $session);
    }
}
