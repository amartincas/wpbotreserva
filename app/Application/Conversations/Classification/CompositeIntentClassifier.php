<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Events\RouterIntentUnresolved;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Chain of Responsibility — itera las estrategias en el orden recibido y
 * devuelve la primera respuesta no nula. El orden es explícito en el
 * binding de AppServiceProvider (continuidad antes que IA), no un registro
 * dinámico con prioridades — dos consumidores reales no justifican esa
 * maquinaria (Parte IX punto 3, mismo criterio contra un Command Bus
 * genérico).
 *
 * Agregar una estrategia nueva (ej. comandos admin determinista,
 * Incremento 2) es una clase nueva insertada en ese array — cero cambios
 * acá, en el Router o en las estrategias existentes.
 */
class CompositeIntentClassifier implements IntentClassifierInterface
{
    /**
     * @param  IntentClassifierStrategy[]  $strategies
     */
    public function __construct(private readonly array $strategies) {}

    public function classify(InboundMessage $message, ConversationSession $session): Intent
    {
        foreach ($this->strategies as $strategy) {
            $intent = $strategy->attempt($message, $session);

            if ($intent !== null) {
                return $intent;
            }
        }

        // Ninguna estrategia pudo siquiera intentarlo (ej. la llamada a IA
        // falló) — distinto de que la IA haya clasificado activamente el
        // mensaje como FueraDeAlcance, que ya habría retornado arriba.
        RouterIntentUnresolved::dispatch($message, $session);

        return Intent::FueraDeAlcance;
    }
}
