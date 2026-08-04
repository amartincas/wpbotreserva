<?php

namespace App\Application\Organizations;

/**
 * Estados deterministas de OrganizationResolverInterface::resolve() — el
 * resolver nunca inicia conversaciones ni ejecuta lógica de negocio, solo
 * informa uno de estos tres hechos (validado explícitamente al aprobar el
 * Hito 4).
 */
enum OrganizationResolutionStatus
{
    case Resolved;
    case PendingDisambiguation;
    case NotFound;
}
