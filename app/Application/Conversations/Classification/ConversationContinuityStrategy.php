<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Primera en la cadena: si la sesión ya tiene un Intent activo (a mitad de
 * un flujo multi-turno, ej. Hito 5 recolectando datos de registro), lo
 * repite sin mirar contenido — un mensaje como "30 minutos" no es
 * clasificable por sí solo. El Agent dueño del flujo es quien limpia
 * current_intent al completarlo (vía recordIntent(session, null)); esta
 * estrategia solo lee, nunca decide cuándo termina un flujo.
 */
class ConversationContinuityStrategy implements IntentClassifierStrategy
{
    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        if ($session->current_intent === null) {
            return null;
        }

        return Intent::tryFrom($session->current_intent);
    }
}
