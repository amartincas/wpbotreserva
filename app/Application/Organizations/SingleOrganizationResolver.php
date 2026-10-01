<?php

namespace App\Application\Organizations;

use App\Application\Contracts\OrganizationResolverInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;

/**
 * Implementación MVP — si la sesión ya tiene una organización resuelta de un
 * mensaje anterior, la reutiliza sin volver a consultar el pivot (evita un
 * join en cada mensaje). Si no, resuelve por cantidad de organizaciones
 * vinculadas al Channel: 1 = resuelta directo, 0 = todavía sin registrar
 * (estado inicial normal de todo Channel nuevo, no un error — dispara el
 * flujo de RegistroNegocioAgent).
 *
 * Fase 6: Channel → 0 o 1 Organization es la regla de negocio definitiva
 * (UNIQUE(channel_id) en channel_organization la hace cumplir también a
 * nivel de base de datos) — 2+ ya no es un estado alcanzable, así que no
 * necesita rama propia acá.
 */
class SingleOrganizationResolver implements OrganizationResolverInterface
{
    public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution
    {
        if ($session->organization_id !== null) {
            $organization = Organization::find($session->organization_id);

            if ($organization !== null) {
                return OrganizationResolution::resolved($organization);
            }
        }

        $organization = $channel->organizations()->first();

        return $organization === null
            ? OrganizationResolution::unregistered()
            : OrganizationResolution::resolved($organization);
    }
}
