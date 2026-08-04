<?php

namespace App\Application\Organizations;

/**
 * Estados deterministas de OrganizationResolverInterface::resolve() — el
 * resolver nunca inicia conversaciones ni ejecuta lógica de negocio, solo
 * informa uno de estos tres hechos (validado explícitamente al aprobar el
 * Hito 4). Los tres son estados **válidos**, ninguno es un error: incluso
 * Unregistered (renombrado de NotFound en el Hito 5, tras descubrir que el
 * alta de un negocio nuevo pasa necesariamente por acá) es el estado
 * inicial normal de todo Channel antes de su primer registro — no una
 * búsqueda fallida.
 */
enum OrganizationResolutionStatus
{
    case Resolved;
    case PendingDisambiguation;
    case Unregistered;
}
