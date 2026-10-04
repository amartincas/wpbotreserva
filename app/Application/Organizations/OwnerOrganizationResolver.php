<?php

namespace App\Application\Organizations;

use App\Application\Contracts\OrganizationResolverInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;

/**
 * Resolución de Organization para el número CENTRAL (B4): quien escribe al
 * CENTRAL es un owner (o alguien que quiere serlo), así que su Organization
 * sale de su propio teléfono — Organization.owner_phone —, nunca del
 * Channel. El CENTRAL no pertenece a ninguna Organization, así que
 * channel_organization no se consulta acá en ningún caso.
 *
 * Tampoco reutiliza session->organization_id como atajo: se consulta
 * owner_phone en cada mensaje (es un índice UNIQUE, una sola fila), así un
 * organization_id memoizado que ya no corresponde nunca se cuela.
 *
 *  - 0 → Unregistered: owner nuevo, puede registrar su negocio.
 *  - 1 → Resolved: owner con negocio, administración.
 *  - 2+ → Inconsistent: UNIQUE(owner_phone) lo impide; MVP sin
 *    multi-Organization por owner, así que se falla cerrado.
 */
class OwnerOrganizationResolver implements OrganizationResolverInterface
{
    public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution
    {
        $organizations = Organization::where('owner_phone', $session->customer_phone?->value())
            ->limit(2)
            ->get();

        return match ($organizations->count()) {
            0 => OrganizationResolution::unregistered(),
            1 => OrganizationResolution::resolved($organizations->first()),
            default => OrganizationResolution::inconsistent('owner_multiple_organizations'),
        };
    }
}
