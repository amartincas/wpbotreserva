<?php

namespace App\Application\Tenancy;

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Domain\Scheduling\Service;
use App\Domain\Scheduling\ServiceResourceRequirement;
use App\Domain\Tenancy\Organization;
use App\Enums\ResourceType;
use Illuminate\Support\Facades\DB;

/**
 * Agrega un servicio a un negocio YA registrado (Incremento 4, punto D de
 * la prueba real — "quiero registrar otro servicios" no tenía ningún
 * flujo). Reutiliza ServiceRegistrationData (mismo DTO que
 * RegisterOrganizationCommand) — es el mismo dato, solo que ahora se
 * agrega a una Organization existente en vez de crearla.
 *
 * Corrección post-prueba real: la primera versión habilitaba TODO recurso
 * existente automáticamente (mismo criterio que el alta inicial). El
 * dueño aclaró que eso no vale para esto — cada servicio tiene su propio
 * recurso o recursos, que pueden coincidir con los de otro servicio pero
 * nunca hay que asumirlo. $resourceIds es explícito, elegido por el dueño
 * en la conversación (ver GestionNegocioAgent), nunca "todos por
 * default" — puede ser uno o varios, ya estaba definido así desde el
 * modelo de dominio (ServiceResourceRequirement/resource_service es N:M).
 */
class AddServiceCommand
{
    public function __construct(private readonly EntitlementCheckerInterface $entitlements) {}

    /**
     * @param  int[]  $resourceIds
     */
    public function handle(Organization $organization, ServiceRegistrationData $data, array $resourceIds): Service
    {
        return DB::transaction(function () use ($organization, $data, $resourceIds) {
            $this->ensureEntitled($organization, 'scheduling.max_services');

            $service = Service::create([
                'organization_id' => $organization->id,
                'name' => $data->name,
                'duration_minutes' => $data->durationMinutes,
            ]);

            ServiceResourceRequirement::create([
                'service_id' => $service->id,
                'resource_type' => ResourceType::HUMAN,
                'quantity' => 1,
            ]);

            $service->resources()->attach($resourceIds);

            return $service;
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
