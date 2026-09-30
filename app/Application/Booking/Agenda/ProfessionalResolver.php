<?php

namespace App\Application\Booking\Agenda;

use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp remitente → Resource.contact_phone → Resource (Fase 4), siempre
 * scoped a la Organization ya resuelta por el Channel — nunca una búsqueda
 * global de Resource. Es lo que garantiza que un profesional de la
 * Organization A nunca resuelva contra la Organization B: la Organization
 * ya viene fijada antes de que esta clase corra. Organization.owner_phone
 * nunca se usa acá ni como fallback.
 */
class ProfessionalResolver
{
    public function resolveFor(Organization $organization, string $fromPhone): ?Resource
    {
        $matches = $organization->resources()->where('contact_phone', $fromPhone)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            // Anomalía de datos (contact_phone repetido dentro de la misma
            // Organization) — fail-closed: adivinar cuál es el remitente
            // real arriesgaría exponer la agenda de un profesional a otro.
            Log::warning('ProfessionalResolver: contact_phone ambiguo dentro de la misma Organization', [
                'organization_id' => $organization->id,
                'resource_ids' => $matches->pluck('id')->all(),
            ]);
        }

        return null;
    }
}
