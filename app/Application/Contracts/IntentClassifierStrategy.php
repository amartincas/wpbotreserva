<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Contrato interno de una estrategia individual dentro de
 * CompositeIntentClassifier (Chain of Responsibility) — nunca se inyecta
 * directamente en Router/AgentSelector, solo en el composite. null =
 * "no tengo opinión, preguntale a la siguiente estrategia"; nunca un Agent
 * concreto, siempre un Intent o nada.
 */
interface IntentClassifierStrategy
{
    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent;
}
