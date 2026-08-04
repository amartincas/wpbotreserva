<?php

namespace App\Application\Conversations;

use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Intent;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * El mutex de Redis (adquirido en el Job, antes de invocar al Router) es la
 * defensa primaria contra dos mensajes simultáneos de la misma conversación.
 * Este unique(channel_id, customer_phone) + retry es defensa en profundidad
 * — si el lock fallara o expirara, la BD sigue siendo la fuente de verdad
 * (mismo principio que BookingScheduler, Hito 2), nunca se duplica una
 * sesión.
 */
class EloquentConversationSessionRepository implements ConversationSessionRepositoryInterface
{
    public function findOrCreateFor(Channel $channel, string $customerPhone): ConversationSession
    {
        $normalizedPhone = (new PhoneNumber($customerPhone))->value();

        try {
            return ConversationSession::firstOrCreate([
                'channel_id' => $channel->id,
                'customer_phone' => $normalizedPhone,
            ]);
        } catch (UniqueConstraintViolationException) {
            // No cubierto por test automatizado: reproducir esta carrera de
            // verdad requiere dos procesos/hilos separados insertando en el
            // mismo milisegundo (mismo límite que ConcurrencyTest.php ya
            // documenta para BookingScheduler) — un test de un solo proceso
            // que fuerce esta rama sería artificial, no evidencia real. Lo
            // que sí está probado: (a) el unique constraint en sí dispara de
            // verdad con dos conexiones reales (ver el test de
            // EloquentConversationSessionRepositoryTest.php sobre esta
            // misma clase); (b) esta misma consulta where().where() ya se
            // ejerce y se prueba correcta en los tests de "sesión existente"
            // de arriba, solo que no a través de este catch puntual.
            return ConversationSession::where('channel_id', $channel->id)
                ->where('customer_phone', $normalizedPhone)
                ->firstOrFail();
        }
    }

    public function attachOrganization(ConversationSession $session, Organization $organization): void
    {
        if ($session->organization_id !== $organization->id) {
            $session->update(['organization_id' => $organization->id]);
        }
    }

    public function recordIntent(ConversationSession $session, ?Intent $intent): void
    {
        $session->update(['current_intent' => $intent?->value]);
    }
}
