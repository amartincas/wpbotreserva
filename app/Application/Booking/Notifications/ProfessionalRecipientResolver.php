<?php

namespace App\Application\Booking\Notifications;

use App\Domain\Booking\Booking;
use Illuminate\Support\Facades\Log;

/**
 * Fase 2B — única responsabilidad: decidir a QUIÉN (si a alguien) notificar
 * del lado del profesional para un Booking dado. No envía nada, no conoce
 * templates ni construye mensajes — eso es responsabilidad de cada listener
 * (Diseño Fase 2B, sección 2, para no convertirse en un God Service).
 *
 * Lee $booking->bookingResources->first() como propiedad (relación ya
 * cargada por el caller vía loadMissing/fresh), nunca
 * $booking->bookingResources()->first() (query nueva) — el Diseño Fase 2B
 * (sección 3/8) depende de esto para evitar cualquier fragilidad de timing
 * con after_commit=false.
 *
 * Decisiones de producto ya aprobadas (Fase 2B):
 *  - Resource.is_active = false NO bloquea el envío — una reserva ya
 *    asignada no pierde su responsable retroactivamente.
 *  - Nunca cae a Organization.owner_phone — son conceptos independientes
 *    desde Fase 2A, sin excepción.
 *  - organization_id se verifica explícitamente porque OrganizationScope
 *    es un no-op hoy (confirmado en la auditoría) — no hay ninguna red de
 *    seguridad de Eloquent por debajo.
 */
final class ProfessionalRecipientResolver
{
    public function resolve(Booking $booking, string $context): ?ResolvedProfessionalRecipient
    {
        $bookingResource = $booking->bookingResources->first();

        if ($bookingResource === null) {
            Log::warning('ProfessionalRecipientResolver: booking sin BookingResource', [
                'booking_id' => $booking->id,
                'context' => $context,
            ]);

            return null;
        }

        // Defensivo: en el schema actual, resource_id es cascadeOnDelete —
        // borrar un Resource borra en cascada su(s) BookingResource, así que
        // este null es teóricamente inalcanzable hoy (ver Diseño Fase 2B,
        // desviación documentada en el informe de implementación). Se
        // mantiene igual por si el schema cambia.
        $resource = $bookingResource->resource;

        if ($resource === null) {
            Log::warning('ProfessionalRecipientResolver: el Resource de este BookingResource ya no existe', [
                'booking_id' => $booking->id,
                'booking_resource_id' => $bookingResource->id,
                'context' => $context,
            ]);

            return null;
        }

        if ($resource->organization_id !== $booking->organization_id) {
            Log::error('ProfessionalRecipientResolver: el Resource pertenece a otra Organization', [
                'booking_id' => $booking->id,
                'booking_organization_id' => $booking->organization_id,
                'resource_id' => $resource->id,
                'resource_organization_id' => $resource->organization_id,
                'context' => $context,
            ]);

            return null;
        }

        if ($resource->contact_phone === null) {
            Log::warning('ProfessionalRecipientResolver: Resource sin contact_phone', [
                'booking_id' => $booking->id,
                'resource_id' => $resource->id,
                'context' => $context,
            ]);

            return null;
        }

        return new ResolvedProfessionalRecipient(
            organization: $booking->organization,
            contactPhone: $resource->contact_phone->value(),
            resourceName: $resource->display_name,
        );
    }
}
