<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;

/**
 * Único punto de escritura de ConversationSession — mantiene a
 * InboundMessageRouter libre de tocar Eloquent directamente (Parte XIII
 * regla 2: el flujo principal se sigue sin atravesar detalles de
 * persistencia).
 */
interface ConversationSessionRepositoryInterface
{
    public function findOrCreateFor(Channel $channel, string $customerPhone): ConversationSession;

    public function attachOrganization(ConversationSession $session, Organization $organization): void;

    /**
     * $intent === null limpia la continuidad — lo usa el Agent dueño de un
     * flujo multi-turno al completarlo (Hito 5/6), nunca el classifier.
     */
    public function recordIntent(ConversationSession $session, ?Intent $intent): void;
}
