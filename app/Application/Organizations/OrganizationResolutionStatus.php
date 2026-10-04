<?php

namespace App\Application\Organizations;

/**
 * Estados deterministas de OrganizationResolverInterface::resolve() — el
 * resolver nunca inicia conversaciones ni ejecuta lógica de negocio, solo
 * informa un hecho. Unregistered (renombrado de NotFound en el Hito 5, tras
 * descubrir que el alta de un negocio nuevo pasa necesariamente por acá)
 * significa "no hay Organization": en el CENTRAL es el owner nuevo (estado
 * normal, habilita el onboarding); en un BUSINESS es un número sin
 * vincular (el Router lo rechaza).
 *
 * Inconsistent (B4): el dato que debería identificar a una única
 * Organization apunta a más de una — owner_phone repetido o un Channel con
 * dos vínculos. Ni UNIQUE(owner_phone) ni UNIQUE(channel_id) lo permiten,
 * así que solo es alcanzable con SQL manual; se informa en vez de elegir
 * una al azar, y el Router falla cerrado (fail closed).
 */
enum OrganizationResolutionStatus
{
    case Resolved;
    case Unregistered;
    case Inconsistent;
}
