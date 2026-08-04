<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Domain\Conversational\ConversationSession;
use Illuminate\Support\Facades\Cache;

/**
 * Mismo TTL que ConversationContinuityStrategy (config('conversations.continuity_ttl_minutes'))
 * a propósito — si la sesión considera vencido el Intent activo, el draft
 * también debe estar vencido; TTLs distintos entre ambos producirían un
 * estado inconsistente (ej. sesión "fresca" pero draft ya perdido, o
 * viceversa), justo lo que se acordó evitar.
 */
class CacheConversationDraftRepository implements ConversationDraftRepositoryInterface
{
    public function get(ConversationSession $session): array
    {
        return Cache::get($this->key($session), []);
    }

    public function put(ConversationSession $session, array $draft): void
    {
        Cache::put($this->key($session), $draft, now()->addMinutes(config('conversations.continuity_ttl_minutes')));
    }

    public function forget(ConversationSession $session): void
    {
        Cache::forget($this->key($session));
    }

    private function key(ConversationSession $session): string
    {
        return "conversation_draft:{$session->id}";
    }
}
