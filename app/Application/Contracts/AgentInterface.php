<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Contrato que ve AgentSelector — cada implementación concreta (Hito 5:
 * Registro de Negocios, Hito 6: Reservas) recibe por constructor solo el/los
 * Application Command(s) de su allow-list (Parte XI punto 7), nunca acceso
 * abierto a la capa de aplicación.
 */
interface AgentInterface
{
    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void;
}
