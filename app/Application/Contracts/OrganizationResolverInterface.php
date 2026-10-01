<?php

namespace App\Application\Contracts;

use App\Application\Organizations\OrganizationResolution;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;

/**
 * Se limita a resolver el contexto de la organización y devolver un estado
 * determinista: no inicia conversaciones, no crea organizaciones, no
 * ejecuta lógica de negocio. Channel → 0 o 1 Organization (Fase 6, forzado
 * también por UNIQUE(channel_id) en channel_organization).
 */
interface OrganizationResolverInterface
{
    public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution;
}
