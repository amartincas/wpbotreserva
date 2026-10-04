<?php

namespace App\Application\Organizations;

use App\Application\Contracts\OrganizationResolverInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;

/**
 * Resolución de Organization para un Channel BUSINESS: el WhatsApp propio
 * de un negocio, vinculado a su Organization vía channel_organization.
 *
 *  - 1 vínculo → Resolved.
 *  - 0 → Unregistered: número sin vincular. Desde B4 el Router lo rechaza
 *    (channel_unlinked) — un BUSINESS nunca abre un onboarding; eso vive
 *    solo en el CENTRAL.
 *  - 2+ → Inconsistent: UNIQUE(channel_id) lo impide (Fase 6), solo con
 *    SQL manual; el Router falla cerrado.
 *
 * B4: ya no reutiliza session->organization_id como atajo. Con la
 * separación CENTRAL/BUSINESS, el vínculo del Channel es la única fuente de
 * verdad — si un número se desvincula de su negocio, una sesión memoizada
 * no puede seguir mandando sus clientes a esa Organization.
 */
class SingleOrganizationResolver implements OrganizationResolverInterface
{
    public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution
    {
        $organizations = $channel->organizations()->limit(2)->get();

        return match ($organizations->count()) {
            0 => OrganizationResolution::unregistered(),
            1 => OrganizationResolution::resolved($organizations->first()),
            default => OrganizationResolution::inconsistent('channel_multiple_organizations'),
        };
    }
}
