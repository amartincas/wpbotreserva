<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Objeto con identidad (no un Closure — decidido explícitamente para
 * mantener stack traces legibles, testeabilidad por tipo, y espacio para
 * decoradores futuros — logging, métricas, retries — sin tocar
 * AgentSelector ni el Router) que uniforma la invocación de un Agent,
 * tenga o no Organization resuelta. El Router solo conoce esta interfaz;
 * nunca sabe si detrás hay un AgentInterface o un OrganizationlessAgentInterface.
 */
interface AgentInvoker
{
    public function handle(InboundMessage $message, ConversationSession $session): void;
}
