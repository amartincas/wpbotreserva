<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Salida de emergencia (Incremento 2, caso real: alguien elige mal en
 * BookingChoiceAgent, o cambia de opinión a mitad de cualquier flujo, y
 * antes no había forma de salir salvo esperar el TTL de continuidad).
 *
 * Coincidencia exacta y deliberadamente distinta de cualquier respuesta
 * válida dentro de un flujo — nunca "cancelar" solo (ya significa "cancelá
 * mi turno" dentro de GestionReservaAgent) — para que nunca se choque con
 * una respuesta real que el cliente quiso dar.
 *
 * Solo reclama el Intent si YA hay un flujo activo (current_intent no
 * nulo): en una conversación nueva no hay nada que reiniciar, así que se
 * deja pasar a la clasificación normal (con estas palabras es muy
 * improbable que la IA lo confunda con reserva/gestión de todos modos).
 *
 * Se ejecuta ANTES que ConversationContinuityStrategy a propósito — si
 * corriera después, la continuidad ya habría repetido el Intent activo y
 * esta estrategia nunca tendría la oportunidad de interrumpirlo.
 */
class ResetKeywordStrategy implements IntentClassifierStrategy
{
    private const RESET_WORDS = ['salir', 'olvidalo', 'olvídalo', 'empezar de nuevo', 'otra cosa'];

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        if ($session->current_intent === null) {
            return null;
        }

        $text = mb_strtolower(trim($message->text));

        if (! in_array($text, self::RESET_WORDS, true)) {
            return null;
        }

        return Intent::Reset;
    }
}
