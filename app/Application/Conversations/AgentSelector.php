<?php

namespace App\Application\Conversations;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\AgentInvoker;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelRole;

/**
 * (ChannelRole, Intent, Organization si existe) → AgentInvoker. Nunca
 * interpreta contenido ni estado de conversación (eso ya lo resolvió
 * IntentClassifierInterface antes). Acá vive la única decisión sobre
 * nulabilidad de Organization en todo el sistema (corrección al Hito 4,
 * validada antes de implementar el Hito 5): si el Agent registrado para el
 * Intent implementa OrganizationlessAgentInterface, no necesita Organization
 * y se invoca igual aunque no haya una resuelta; si implementa el
 * AgentInterface normal, requiere una Organization real.
 *
 * B4 — un mapa por rol del Channel, y cada mapa ES la allowlist de ese rol:
 * el CENTRAL atiende onboarding y administración del owner, un BUSINESS
 * atiende a los clientes del negocio. Un Intent que el rol no atiende (o
 * que requiere una Organization que no hay) se convierte en FueraDeAlcance
 * de ESE rol vía effectiveIntent(), que el Router aplica ANTES de
 * recordIntent() — así current_intent nunca guarda un Intent que el Channel
 * no puede atender (y que la continuidad repetiría mensaje tras mensaje).
 */
class AgentSelector
{
    /**
     * @param  array<string, AgentInterface|OrganizationlessAgentInterface>  $centralAgents  Keyed by Intent::value
     * @param  array<string, AgentInterface|OrganizationlessAgentInterface>  $businessAgents  Keyed by Intent::value
     */
    public function __construct(
        private readonly array $centralAgents,
        private readonly array $businessAgents,
    ) {}

    public function allows(ChannelRole $role, Intent $intent): bool
    {
        return $this->agentFor($role, $intent) !== null;
    }

    /**
     * El Intent que efectivamente se atiende en este rol: el clasificado si
     * el rol lo atiende y hay invoker posible; si no, FueraDeAlcance.
     */
    public function effectiveIntent(ChannelRole $role, Intent $intent, ?Organization $organization): Intent
    {
        return $this->selectFor($role, $intent, $organization) !== null
            ? $intent
            : Intent::FueraDeAlcance;
    }

    public function selectFor(ChannelRole $role, Intent $intent, ?Organization $organization): ?AgentInvoker
    {
        $agent = $this->agentFor($role, $intent);

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

    private function agentFor(ChannelRole $role, Intent $intent): AgentInterface|OrganizationlessAgentInterface|null
    {
        $agents = $role === ChannelRole::CENTRAL ? $this->centralAgents : $this->businessAgents;

        return $agents[$intent->value] ?? null;
    }
}
