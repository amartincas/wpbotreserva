<?php

namespace App\Application\Conversations\Flows;

use App\Application\Tenancy\AddResourceCommand;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Organization;

/**
 * Catálogo para GestionNegocioAgent: la Organization ya existe, así que
 * "existente" es una consulta a BD y "nuevo" se persiste de una, vía el
 * mismo AddResourceCommand que ya usaba GestionNegocioAgent directamente.
 */
final class PersistedResourceCatalog implements ResourceCatalogInterface
{
    public function __construct(
        private readonly Organization $organization,
        private readonly AddResourceCommand $addResource,
    ) {}

    public function listExisting(array $draft): array
    {
        return $this->organization->resources()->orderBy('id')->get()
            ->map(fn (Resource $resource) => ['id' => $resource->id, 'name' => $resource->display_name])
            ->all();
    }

    public function createNew(array $draft, string $name, array $schedule): array
    {
        $resource = $this->addResource->handle($this->organization, new ResourceRegistrationData($name, $schedule));

        return [$draft, $resource->id];
    }
}
