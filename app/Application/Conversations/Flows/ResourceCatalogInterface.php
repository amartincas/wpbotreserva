<?php

namespace App\Application\Conversations\Flows;

use App\Application\Tenancy\WeeklyScheduleSlot;

/**
 * Fuente de recursos que consulta/alimenta ServiceResourceSelectionFlow —
 * abstrae la diferencia real entre los dos contextos donde se selecciona
 * quién presta un servicio:
 *  - GestionNegocioAgent: la Organization YA existe, los recursos son filas
 *    reales de BD (PersistedResourceCatalog).
 *  - RegistroNegocioAgent: la Organization todavía NO existe — nada se
 *    persiste hasta la confirmación final, los recursos viven en el draft
 *    de la conversación (DraftResourceCatalog).
 *
 * El "id" que devuelve cada implementación es opaco para el Flow: un id
 * real de BD en un caso, un índice dentro de $draft['resources'] en el
 * otro. Ambos sirven igual para armar el menú numerado y para acumular
 * "qué recursos quedaron elegidos para este servicio".
 */
interface ResourceCatalogInterface
{
    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array{id: int|string, name: string}> en orden estable
     */
    public function listExisting(array $draft): array;

    /**
     * @param  array<string, mixed>  $draft
     * @param  WeeklyScheduleSlot[]  $schedule
     * @return array{0: array<string, mixed>, 1: int|string} [$draft actualizado, id del recurso nuevo]
     */
    public function createNew(array $draft, string $name, array $schedule): array;
}
