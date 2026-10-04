<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Consulta de agenda (Fase 4; B7: del OWNER, sobre toda su Organization):
 * coincidencia determinista,
 * nunca IA. Valida solo la FORMA de la referencia de fecha acá (mismo
 * criterio que DeterministicAdminCommandStrategy con "reservas dd/mm/aaaa"):
 * si la fecha es calendáricamente válida lo decide AgendaDateResolver
 * dentro del Agent, no esta clase — así "31/02/2026" sí se clasifica y el
 * Agent puede responder agenda.fecha_invalida en vez de que el mensaje se
 * pierda en silencio acá.
 *
 * Doble verificación antes de reclamar el Intent: (a) el texto trae una
 * referencia de fecha con forma reconocible + estructura de PREGUNTA sobre
 * agenda, (b) quien escribe es el owner_phone de la Organization ya
 * resuelta para esta sesión — mismo gate que
 * DeterministicAdminCommandStrategy. B7: antes era el profesional,
 * identificado por el teléfono propio del Resource; la agenda es ahora una consulta
 * administrativa del dueño desde el CENTRAL. Si (b) falla (un cliente,
 * cualquier otro número), esta estrategia NO revela que el comando existe
 * — devuelve null, mismo criterio de seguridad que el resto de las
 * estrategias deterministas del dueño.
 *
 * Corrección de falso positivo (post-implementación): "mañana tengo turno
 * con el dentista" matcheaba antes porque "turno" ya es, por sí solo, uno
 * de los sustantivos de agenda — acotar solo la palabra "tengo" NO alcanza.
 * Ahora se exige además una palabra interrogativa (qué/cuánto-a-s/cuáles)
 * en el mismo mensaje — presente en los 4 ejemplos legítimos ("¿qué tengo
 * mañana?", "¿cuántas tengo mañana?", etc.), ausente en una afirmación como
 * la del ejemplo de arriba. Determinista, sin IA/NLP.
 */
class DeterministicAgendaProfesionalStrategy implements IntentClassifierStrategy
{
    private const DATE_PATTERN = '/\bhoy\b|\bma[ñn]ana\b|\b\d{1,2}\/\d{1,2}\/\d{4}\b|\b\d{1,2}\s+de\s+[a-záéíóúñ]+\b/iu';

    private const AGENDA_NOUN_PATTERN = '/\b(citas?|turnos?|reservas?|agenda)\b/iu';

    private const TENGO_PATTERN = '/\btengo\b/iu';

    private const INTERROGATIVE_PATTERN = '/\b(qu[eé]|cu[aá]nt[oa]s?|cu[aá]les)\b/iu';

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        $text = trim($message->text);

        if (! preg_match(self::DATE_PATTERN, $text)) {
            return null;
        }

        if (! preg_match(self::INTERROGATIVE_PATTERN, $text)) {
            return null;
        }

        if (! preg_match(self::AGENDA_NOUN_PATTERN, $text) && ! preg_match(self::TENGO_PATTERN, $text)) {
            return null;
        }

        $organization = $session->organization;

        if ($organization === null) {
            return null;
        }

        if ($organization->owner_phone === null || $organization->owner_phone !== $message->fromPhone) {
            return null;
        }

        return Intent::AgendaProfesional;
    }
}
