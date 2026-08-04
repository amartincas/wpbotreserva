<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Caso especial (Hito 5, corrección al diseño del Router del Hito 4): un
 * Agent que puede operar sin una Organization resuelta — hoy, exclusivamente
 * RegistroNegocioAgent, disparado cuando OrganizationResolverInterface
 * devuelve Unregistered. Interfaz deliberadamente distinta de
 * AgentInterface (no ?Organization en la misma firma) para que ningún Agent
 * "normal" (Reservas, Gestión, futuros) cargue con un caso que nunca les
 * aplica. AgentSelector es el único que distingue cuál de las dos
 * implementa un Agent concreto — el Router nunca lo sabe.
 */
interface OrganizationlessAgentInterface
{
    public function handle(InboundMessage $message, ConversationSession $session): void;
}
