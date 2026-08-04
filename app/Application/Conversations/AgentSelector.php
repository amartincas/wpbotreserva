<?php

namespace App\Application\Conversations;

use App\Application\Contracts\AgentInterface;
use App\Domain\Conversational\Intent;

/**
 * Lookup puro Intent → Agent — nunca interpreta contenido ni estado de
 * conversación (eso ya lo resolvió IntentClassifierInterface antes). Si el
 * día de mañana la selección necesitara depender de más que el Intent (ej.
 * workflows por organización), esa responsabilidad se extrae a una nueva
 * pieza sin modificar Router (acordado antes del Hito 4) — este selector
 * seguiría siendo, en el peor caso, quien recibe el resultado ya decidido.
 */
class AgentSelector
{
    /**
     * @param  array<string, AgentInterface>  $agentsByIntent  Keyed by Intent::value
     */
    public function __construct(private readonly array $agentsByIntent) {}

    public function selectFor(Intent $intent): ?AgentInterface
    {
        return $this->agentsByIntent[$intent->value] ?? null;
    }
}
