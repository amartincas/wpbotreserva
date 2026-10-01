<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Fase 6: detecta un registro de negocio abandonado y vencido ANTES de que
 * ConversationContinuityStrategy lo deje caer en silencio a reclasificación.
 * Mismo cálculo de vencimiento que esa estrategia (mismo TTL,
 * config('conversations.continuity_ttl_minutes')) — a propósito, no debe
 * duplicar ni contradecir ese criterio, solo intercepta el único caso en el
 * que el proyecto quiere avisar explícitamente en vez de reclasificar mudo:
 * un RegistroNegocio vencido.
 *
 * Tiene que correr ANTES de que el Router sobrescriba current_intent con el
 * resultado de este mismo mensaje (recordIntent() corre después de
 * clasificar, InboundMessageRouter::handle()) — es la única ventana en la
 * que current_intent todavía conserva el valor viejo sin pisar.
 *
 * Posición en la cadena (AppServiceProvider): después de ButtonIntentStrategy
 * (un clic explícito en "Registrar negocio" ya es la decisión del dueño de
 * empezar de nuevo — no tiene sentido interponer un aviso de expiración) y
 * antes de ConversationContinuityStrategy (que es quien, para cualquier otro
 * Intent, simplemente deja de considerarlo activo sin avisar).
 */
class ExpiredRegistroNegocioStrategy implements IntentClassifierStrategy
{
    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        if ($session->current_intent !== Intent::RegistroNegocio->value) {
            return null;
        }

        $ttlMinutes = config('conversations.continuity_ttl_minutes');

        if ($session->updated_at->diffInMinutes(now()) <= $ttlMinutes) {
            return null;
        }

        return Intent::RegistroNegocioExpirado;
    }
}
