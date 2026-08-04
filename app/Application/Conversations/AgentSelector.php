<?php

namespace App\Application\Conversations;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\AgentInvoker;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Organization;

/**
 * Intent (+ Organization, si existe) → AgentInvoker. Nunca interpreta
 * contenido ni estado de conversación (eso ya lo resolvió
 * IntentClassifierInterface antes). Acá vive la única decisión sobre
 * nulabilidad de Organization en todo el sistema (corrección al Hito 4,
 * validada antes de implementar el Hito 5): si el Agent registrado para el
 * Intent implementa OrganizationlessAgentInterface, no necesita Organization
 * y se invoca igual aunque no haya una resuelta; si implementa el
 * AgentInterface normal, requiere una Organization real — sin ella, no hay
 * invoker disponible (mismo significado que "no hay Agent para este
 * Intent"). El Router nunca ve esta distinción, solo el AgentInvoker
 * resultante.
 */
class AgentSelector
{
    /**
     * @param  array<string, AgentInterface|OrganizationlessAgentInterface>  $agentsByIntent  Keyed by Intent::value
     */
    public function __construct(private readonly array $agentsByIntent) {}

    public function selectFor(Intent $intent, ?Organization $organization): ?AgentInvoker
    {
        $agent = $this->agentsByIntent[$intent->value] ?? null;

        if ($agent === null) {
            return null;
        }

        if ($agent instanceof OrganizationlessAgentInterface) {
            return new OrganizationlessAgentInvoker($agent);
        }

        if ($organization === null) {
            return null;
        }

        /** @var AgentInterface $agent */
        return new OrganizationAgentInvoker($agent, $organization);
    }
}
