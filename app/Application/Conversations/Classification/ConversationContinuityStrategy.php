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
 *
 * Vencimiento (Hito 5): si pasó más de config('conversations.continuity_ttl_minutes')
 * desde el último mensaje de la sesión, se trata como si no hubiera Intent
 * activo — evita que un flujo abandonado (ej. registro a medio terminar,
 * cliente que nunca vuelve) capture para siempre el próximo mensaje real,
 * aunque sea meses después. No limpia current_intent acá: el Router ya
 * sobreescribe con recordIntent() en cada mensaje, así que devolver null
 * alcanza — esta estrategia solo lee, nunca muta.
 */
class ConversationContinuityStrategy implements IntentClassifierStrategy
{
    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        if ($session->current_intent === null) {
            return null;
        }

        $ttlMinutes = config('conversations.continuity_ttl_minutes');

        if ($session->updated_at->diffInMinutes(now()) > $ttlMinutes) {
            return null;
        }

        return Intent::tryFrom($session->current_intent);
    }
}
