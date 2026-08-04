<?php

namespace App\Application\Organizations;

use App\Application\Contracts\OrganizationResolverInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;

/**
 * Implementación MVP (Parte XIV) — si la sesión ya tiene una organización
 * resuelta de un mensaje anterior, la reutiliza sin volver a consultar el
 * pivot (evita un join en cada mensaje). Si no, resuelve por cantidad de
 * organizaciones vinculadas al Channel: 1 = resuelta directo, 0 = no
 * encontrada, 2+ = pendiente de desambiguación (el matching por nombre real
 * es roadmap — Parte XIV — disparado por un segundo piloto activo).
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

        $candidates = $channel->organizations()->get();

        return match ($candidates->count()) {
            0 => OrganizationResolution::notFound(),
            1 => OrganizationResolution::resolved($candidates->first()),
            default => OrganizationResolution::pendingDisambiguation($candidates->all()),
        };
    }
}
