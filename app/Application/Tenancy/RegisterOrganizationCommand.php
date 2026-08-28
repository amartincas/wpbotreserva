<?php

namespace App\Application\Tenancy;

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el alta de un negocio nuevo (lo invoca el Agente Registro de
 * Negocios, Hito 5). No es lógica de dominio: coordina la creación de
 * varios aggregates y consulta EntitlementChecker antes de cada uno (Parte
 * X) — Scheduling nunca se entera de que existe un plan detrás.
 */
class RegisterOrganizationCommand
{
    public function __construct(private readonly EntitlementCheckerInterface $entitlements) {}

    public function handle(RegisterOrganizationData $data): RegisterOrganizationResult
    {
        return DB::transaction(function () use ($data) {
            $organization = Organization::create([
                'name' => $data->organizationName,
                'owner_phone' => $data->ownerPhone,
            ]);

            $data->channel->organizations()->syncWithoutDetaching([
                $organization->id => ['is_primary' => true],
            ]);

            $this->ensureEntitled($organization, 'scheduling.max_locations');
            $location = Location::create([
                'organization_id' => $organization->id,
                'name' => 'Sede principal',
                'city' => $data->city,
                'address' => $data->address,
            ]);

            $this->ensureEntitled($organization, 'scheduling.max_resources', count($data->resources));
            $resourceIds = [];
            foreach ($data->resources as $index => $resourceData) {
                $resource = Resource::create([
                    'organization_id' => $organization->id,
                    'location_id' => $location->id,
                    'resource_type' => ResourceType::HUMAN,
                    'display_name' => $resourceData->name,
                ]);

                foreach ($resourceData->weeklySchedule as $slot) {
                    ResourceSchedule::create([
                        'resource_id' => $resource->id,
                        'weekday' => $slot->weekday,
                        'start_time' => $slot->startTime,
                        'end_time' => $slot->endTime,
                    ]);
                }

                // Se indexa por la posición original en $data->resources
                // (no array_push secuencial) porque es exactamente ese
                // índice el que ServiceRegistrationData::$resourceKeys usa
                // para referenciar "cuál de los recursos recolectados en la
                // conversación" — ver DraftResourceCatalog.
                $resourceIds[$index] = $resource->id;
            }

            $this->ensureEntitled($organization, 'scheduling.max_services', count($data->services));
            $serviceIds = [];
            foreach ($data->services as $serviceData) {
                $service = Service::create([
                    'organization_id' => $organization->id,
                    'name' => $serviceData->name,
                    'duration_minutes' => $serviceData->durationMinutes,
                ]);

                ServiceResourceRequirement::create([
                    'service_id' => $service->id,
                    'resource_type' => ResourceType::HUMAN,
                    'quantity' => 1,
                ]);

                // Cada servicio queda asociado solo a los recursos elegidos
                // explícitamente para él durante la conversación — ya no
                // "todo recurso presta todo servicio" (ver ServiceResourceSelectionFlow).
                $service->resources()->attach(array_map(
                    fn (int $key) => $resourceIds[$key],
                    $serviceData->resourceKeys,
                ));

                $serviceIds[] = $service->id;
            }

            return new RegisterOrganizationResult(
                organizationId: $organization->id,
                organizationName: $organization->name,
                locationId: $location->id,
                resourceIds: $resourceIds,
                serviceIds: $serviceIds,
            );
        });
    }

    private function ensureEntitled(Organization $organization, string $entitlementKey, int $requestedQuantity = 1): void
    {
        if (! $this->entitlements->check($organization, $entitlementKey, $requestedQuantity)) {
            throw new EntitlementDeniedException(
                "La organización #{$organization->id} alcanzó el límite de «{$entitlementKey}» de su plan."
            );
        }
    }
}
