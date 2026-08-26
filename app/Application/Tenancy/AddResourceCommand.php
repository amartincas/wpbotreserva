<?php

namespace App\Application\Tenancy;

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Agrega una persona/recurso nueva a un negocio YA registrado — mismo
 * ingrediente que RegisterOrganizationCommand crea inline durante el alta,
 * ahora expuesto como su propio Application Command para que
 * GestionNegocioAgent pueda ofrecerlo al agregar un servicio (caso real:
 * "debe comportarse tal cual como se crea el primer servicio, debe
 * preguntar el recurso y su horario" — el dueño espera poder dar de alta
 * una persona nueva ahí mismo, no solo elegir entre las que ya existen).
 *
 * Usa la Location principal del negocio (Incremento 1: un negocio tiene una
 * sola Location, "Sede principal") — no pide cuál, no hay elección real.
 */
class AddResourceCommand
{
    public function __construct(private readonly EntitlementCheckerInterface $entitlements) {}

    public function handle(Organization $organization, ResourceRegistrationData $data): Resource
    {
        return DB::transaction(function () use ($organization, $data) {
            $this->ensureEntitled($organization, 'scheduling.max_resources');

            $location = $organization->locations()->first();

            $resource = Resource::create([
                'organization_id' => $organization->id,
                'location_id' => $location?->id,
                'resource_type' => ResourceType::HUMAN,
                'display_name' => $data->name,
            ]);

            foreach ($data->weeklySchedule as $slot) {
                ResourceSchedule::create([
                    'resource_id' => $resource->id,
                    'weekday' => $slot->weekday,
                    'start_time' => $slot->startTime,
                    'end_time' => $slot->endTime,
                ]);
            }

            return $resource;
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
