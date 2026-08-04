<?php

namespace App\Domain\Conversational\Events;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Catalogado en Parte XI punto 8. Se dispara únicamente cuando ninguna
 * IntentClassifierStrategy pudo siquiera intentar una clasificación (ej.
 * la llamada a IA falló) — distinto de que la IA haya clasificado
 * activamente el mensaje como FueraDeAlcance, que es un resultado de
 * negocio válido y no dispara este evento.
 */
class RouterIntentUnresolved
{
    use Dispatchable;

    public function __construct(
        public readonly InboundMessage $message,
        public readonly ConversationSession $session,
    ) {}
}
