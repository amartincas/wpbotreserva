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
 * Todo recurso YA existente del negocio queda habilitado para el servicio
 * nuevo — mismo criterio que el alta inicial (RegisterOrganizationData):
 * cualquier recurso puede prestar cualquier servicio, sin preguntar
 * asignación fina por conversación todavía.
 */
class AddServiceCommand
{
    public function __construct(private readonly EntitlementCheckerInterface $entitlements) {}

    public function handle(Organization $organization, ServiceRegistrationData $data): Service
    {
        return DB::transaction(function () use ($organization, $data) {
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

            $service->resources()->attach($organization->resources()->pluck('id'));

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
