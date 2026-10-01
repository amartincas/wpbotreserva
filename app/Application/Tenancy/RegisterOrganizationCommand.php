<?php

namespace App\Application\Tenancy;

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\ChannelAlreadyRegisteredException;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Illuminate\Database\UniqueConstraintViolationException;
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
                'description' => $data->organizationDescription,
                'owner_phone' => $data->ownerPhone,
            ]);

            // Fase 6 — capa 2 de la protección contra doble registro (la
            // capa 1 es el guard de InboundMessageRouter, que cubre el caso
            // común). La Organization tiene que existir primero porque acá
            // recién se conoce su id — pero el vínculo se intenta ANTES de
            // crear Location/Resources/Services, para fallar rápido sin
            // hacer trabajo de más cuando sí va a fallar. UNIQUE(channel_id)
            // en channel_organization es lo que detecta la condición de
            // carrera real entre dos remitentes distintos del mismo Channel
            // confirmando casi al mismo tiempo (el mutex de Redis del Job es
            // por remitente, no por Channel, así que no los serializa entre
            // sí — ver ChannelAlreadyRegisteredException). Si falla, el
            // throw revierte toda la transacción: la Organization recién
            // creada arriba nunca llega a persistir.
            try {
                $data->channel->organizations()->syncWithoutDetaching([
                    $organization->id => ['is_primary' => true],
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new ChannelAlreadyRegisteredException(
                    "El Channel #{$data->channel->id} ya tiene una Organization registrada.",
                    previous: $e,
                );
            }

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
                    'contact_phone' => $resourceData->contactPhone,
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
                    'description' => $serviceData->description,
                    'duration_minutes' => $serviceData->durationMinutes,
                    'price' => $serviceData->price,
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
