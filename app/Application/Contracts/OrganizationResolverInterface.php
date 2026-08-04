<?php

namespace App\Application\Contracts;

use App\Application\Organizations\OrganizationResolution;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;

/**
 * Se limita a resolver el contexto de la organización y devolver un estado
 * determinista (Parte XIV, corregida en la revisión previa al Hito 4): no
 * inicia conversaciones, no crea organizaciones, no ejecuta lógica de
 * negocio. El flujo de desambiguación con el cliente (si PendingDisambiguation)
 * es responsabilidad de un Agent disparado por el Router, nunca de este
 * resolver.
 */
interface OrganizationResolverInterface
{
    public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution;
}
