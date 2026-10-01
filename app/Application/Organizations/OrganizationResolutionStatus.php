<?php

namespace App\Application\Organizations;

/**
 * Estados deterministas de OrganizationResolverInterface::resolve() — el
 * resolver nunca inicia conversaciones ni ejecuta lógica de negocio, solo
 * informa uno de estos dos hechos. Ninguno es un error: Unregistered
 * (renombrado de NotFound en el Hito 5, tras descubrir que el alta de un
 * negocio nuevo pasa necesariamente por acá) es el estado inicial normal de
 * todo Channel antes de su primer registro, no una búsqueda fallida.
 *
 * Fase 6: "un Channel puede estar vinculado a varias Organizations" dejó de
 * ser un estado válido del modelo (antes cubierto acá por
 * PendingDisambiguation) — la regla de negocio definitiva es Channel → 0 o 1
 * Organization, forzada también a nivel de base de datos
 * (UNIQUE(channel_id) en channel_organization). Ya no hace falta un tercer
 * estado para algo que la propia base de datos no permite que ocurra.
 */
enum OrganizationResolutionStatus
{
    case Resolved;
    case Unregistered;
}
