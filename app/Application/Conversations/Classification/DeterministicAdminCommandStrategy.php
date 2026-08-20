<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Comandos administrativos deterministas (Incremento 2, Parte VII): coincidencia
 * exacta de palabra clave, nunca IA — se ejecuta antes que
 * AiIntentClassifierStrategy en la cadena de CompositeIntentClassifier (cero
 * cambios al Router, tal como anticipa su docblock).
 *
 * Doble verificación antes de reclamar el Intent: (a) el texto matchea
 * exactamente uno de los 3 comandos, (b) quien escribe es el owner_phone de
 * la Organization ya resuelta para esta sesión. Si (b) falla, esta
 * estrategia NO revela que el comando existe — devuelve null y deja que la
 * cadena siga con la clasificación normal por IA, para que un cliente
 * cualquiera que tipee "cancelar 5" por coincidencia reciba una respuesta
 * conversacional normal, no un mensaje de "no autorizado" que delataría la
 * existencia de esta capa.
 */
class DeterministicAdminCommandStrategy implements IntentClassifierStrategy
{
    private const PATTERN = '/^(reservas\s+hoy|cancelar\s+\d+|confirmar\s+\d+)$/iu';

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        $text = trim($message->text);

        if (! preg_match(self::PATTERN, $text)) {
            return null;
        }

        $organization = $session->organization;

        if ($organization === null || $organization->owner_phone === null) {
            return null;
        }

        if ($organization->owner_phone !== $message->fromPhone) {
            return null;
        }

        return Intent::AdminCommand;
    }
}
