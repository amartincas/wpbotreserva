<?php

namespace App\Application\Conversations\Flows;

/**
 * Catálogo para RegistroNegocioAgent: la Organization todavía no existe, así
 * que "existente" y "nuevo" viven enteramente en $draft['resources'] — sin
 * tocar la base de datos hasta la confirmación final. El "id" que expone
 * cada recurso es su índice dentro de ese array, estable mientras solo se
 * agreguen elementos (nunca se borran) — el mismo índice es el que
 * ServiceRegistrationData::$resourceKeys guarda para cada servicio y el que
 * RegisterOrganizationCommand resuelve a un id real al persistir.
 */
final class DraftResourceCatalog implements ResourceCatalogInterface
{
    public function listExisting(array $draft): array
    {
        $resources = $draft['resources'] ?? [];

        return array_map(
            fn (int $index, array $resource) => ['id' => $index, 'name' => $resource['name']],
            array_keys($resources),
            $resources,
        );
    }

    public function createNew(array $draft, string $name, array $schedule): array
    {
        $draft['resources'] ??= [];
        $draft['resources'][] = ['name' => $name, 'weeklySchedule' => $schedule];
        $index = array_key_last($draft['resources']);

        return [$draft, $index];
    }
}
