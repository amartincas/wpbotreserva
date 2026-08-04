<?php

namespace App\Application\Contracts;

use App\Domain\Conversational\ConversationSession;

/**
 * Abstrae la memoria de trabajo de un flujo multi-turno (Parte XII: vive en
 * Cache, no en ConversationSession). Cualquier Agent con un flujo de varios
 * pasos la usa igual — no hay nada específico de Registro o Reservas acá.
 */
interface ConversationDraftRepositoryInterface
{
    /**
     * @return array<string, mixed>
     */
    public function get(ConversationSession $session): array;

    /**
     * @param  array<string, mixed>  $draft
     */
    public function put(ConversationSession $session, array $draft): void;

    public function forget(ConversationSession $session): void;
}
