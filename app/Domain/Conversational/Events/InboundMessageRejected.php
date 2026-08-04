<?php

namespace App\Domain\Conversational\Events;

use App\Domain\Conversational\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de falla (Parte XI punto 8) — sirve a la vez como señal de negocio
 * (cuántos mensajes no se pudieron procesar y por qué) y como gancho de
 * observabilidad técnica. $reason: channel_not_found | channel_inactive |
 * organization_not_found | organization_pending_disambiguation |
 * agent_not_available.
 */
class InboundMessageRejected
{
    use Dispatchable;

    public function __construct(
        public readonly InboundMessage $message,
        public readonly string $reason,
    ) {}
}
