<?php

namespace App\Domain\Conversational\Events;

use App\Domain\Conversational\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de falla (Parte XI punto 8) — sirve a la vez como señal de negocio
 * (cuántos mensajes no se pudieron procesar y por qué) y como gancho de
 * observabilidad técnica. $reason: channel_not_found | channel_inactive |
 * organization_pending_disambiguation | agent_not_available (cubre tanto
 * "no hay Agent para este Intent" como "el Agent requiere Organization y
 * el Channel todavía no tiene una" — Hito 5, ver AgentSelector).
 */
class InboundMessageRejected
{
    use Dispatchable;

    public function __construct(
        public readonly InboundMessage $message,
        public readonly string $reason,
    ) {}
}
