<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Mismo criterio que DeterministicAdminCommandStrategy (Incremento 2):
 * coincidencia exacta de frase, nunca IA, y solo reclama el Intent si quien
 * escribe es el owner_phone de la Organization ya resuelta — si no, no
 * revela que esta capa existe, deja que la clasificación normal siga su
 * curso. Frases fijas a propósito (no NLP): es una acción sensible de un
 * único dueño, y ya vimos en este mismo incremento que la IA falla de
 * forma real e impredecible en frases mucho más simples ("quiero registrar
 * mi negocio").
 *
 * El primer turno de GestionNegocioAgent siempre pregunta con botones qué
 * quiere hacer el dueño — esta estrategia solo necesita reconocer que
 * quiere gestionar ALGO, no cuál de las dos acciones en particular.
 */
class DeterministicBusinessManagementStrategy implements IntentClassifierStrategy
{
    private const TRIGGER_PHRASES = [
        'agregar servicio',
        'agregar un servicio',
        'agregar un servicio nuevo',
        'nuevo servicio',
        'registrar otro servicio',
        'registrar otro servicios',
        'cambiar horario',
        'cambiar el horario',
        'modificar horario',
        'cambiar la agenda',
        'administrar negocio',
        'administrar mi negocio',
        'gestionar negocio',
        'gestionar mi negocio',
    ];

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        $text = mb_strtolower(trim($message->text));

        if (! in_array($text, self::TRIGGER_PHRASES, true)) {
            return null;
        }

        $organization = $session->organization;

        if ($organization === null || $organization->owner_phone === null) {
            return null;
        }

        if ($organization->owner_phone !== $message->fromPhone) {
            return null;
        }

        return Intent::GestionNegocio;
    }
}
