<?php

namespace App\Application\Conversations;

use App\Application\Contracts\ChannelClientInterface;
use App\Domain\Conversational\ConversationSession;

/**
 * Responder DENTRO de una conversación (B3): siempre por el mismo Channel
 * por el que llegó el mensaje — el de la sesión —, nunca por "el Channel de
 * la Organization". La diferencia importa desde la separación
 * CENTRAL/BUSINESS: un owner que administra su negocio desde el CENTRAL
 * tiene que recibir la respuesta en el CENTRAL, aunque su Organization
 * tenga (o todavía no tenga) un Channel BUSINESS propio; y un owner nuevo
 * en pleno onboarding no tiene Organization alguna.
 *
 * NotificationSenderInterface sigue siendo la vía para envíos iniciados
 * por el sistema hacia una Organization ya conocida (confirmaciones de
 * reserva, recordatorios) — eso no es una respuesta a un mensaje.
 *
 * Mismo criterio que RegistroNegocioAgent/RegistroNegocioExpiradoAgent, que
 * ya respondían así porque nacieron sin Organization.
 */
class ConversationReplier
{
    public function __construct(private readonly ChannelClientInterface $client) {}

    public function send(ConversationSession $session, string $toPhoneE164, string $message): void
    {
        $this->client->sendTextMessage($session->channel, $toPhoneE164, $message);
    }

    /**
     * @param  array<int, array{id: string, title: string}>  $buttons
     */
    public function sendButtons(ConversationSession $session, string $toPhoneE164, string $bodyText, array $buttons): void
    {
        $this->client->sendButtonsMessage($session->channel, $toPhoneE164, $bodyText, $buttons);
    }
}
