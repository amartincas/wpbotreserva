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

            $this->ensureEntitled($organization, 'scheduling.max_resources');
            $resource = Resource::create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'resource_type' => ResourceType::HUMAN,
                'display_name' => $data->resourceName,
            ]);

            $this->ensureEntitled($organization, 'scheduling.max_services');
            $service = Service::create([
                'organization_id' => $organization->id,
                'name' => $data->serviceName,
                'duration_minutes' => $data->serviceDurationMinutes,
            ]);

            ServiceResourceRequirement::create([
                'service_id' => $service->id,
                'resource_type' => ResourceType::HUMAN,
                'quantity' => 1,
            ]);

            $service->resources()->attach($resource->id);

            foreach ($data->weeklySchedule as $slot) {
                ResourceSchedule::create([
                    'resource_id' => $resource->id,
                    'weekday' => $slot->weekday,
                    'start_time' => $slot->startTime,
                    'end_time' => $slot->endTime,
                ]);
            }

            return new RegisterOrganizationResult(
                organizationId: $organization->id,
                organizationName: $organization->name,
                locationId: $location->id,
                resourceId: $resource->id,
                serviceId: $service->id,
            );
        });
    }

    private function ensureEntitled(Organization $organization, string $entitlementKey): void
    {
        if (! $this->entitlements->check($organization, $entitlementKey)) {
            throw new EntitlementDeniedException(
                "La organización #{$organization->id} alcanzó el límite de «{$entitlementKey}» de su plan."
            );
        }
    }
}
