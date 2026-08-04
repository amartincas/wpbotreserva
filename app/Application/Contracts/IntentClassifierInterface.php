<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Único contrato que ve el Router — se limita a producir un Intent, nunca
 * ejecuta lógica conversacional, lógica de dominio ni llamadas a
 * Application Commands (acordado antes del Hito 4). Siempre devuelve una
 * decisión (nunca null): la implementación real (CompositeIntentClassifier)
 * garantiza un default cuando ninguna estrategia responde.
 */
interface IntentClassifierInterface
{
    public function classify(InboundMessage $message, ConversationSession $session): Intent;
}
