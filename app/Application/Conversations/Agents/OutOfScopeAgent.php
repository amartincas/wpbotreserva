<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

/**
 * Único Agent real del Hito 4 — cierra el pipeline completo (Router →
 * Classifier → AgentSelector → Agent → Command/NotificationSender) sin
 * necesitar los agentes de negocio, que son Hito 5/6. No invoca ningún
 * Application Command (no hay dominio que mutar acá) — solo
 * NotificationSenderInterface, dentro de su allow-list.
 */
class OutOfScopeAgent implements AgentInterface
{
    public function __construct(private readonly NotificationSenderInterface $notifications) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        // Botones en vez de pedirle al cliente que lo escriba: caso real
        // (Incremento 4) donde el clasificador de IA falló varias veces
        // seguidas ante frases inequívocas como "quiero registrar mi
        // negocio" — con un conjunto cerrado de 3 opciones, ButtonIntentStrategy
        // reconoce la respuesta con coincidencia exacta, sin volver a pasar
        // por la IA.
        $this->notifications->sendButtons(
            $organization,
            $message->fromPhone,
            'Hola, soy el asistente de WpbotReserva. ¿En qué te ayudo?',
            [
                ['id' => 'menu_registro_negocio', 'title' => 'Registrar negocio'],
                ['id' => 'menu_reserva', 'title' => 'Reservar turno'],
                ['id' => 'menu_gestion_reserva', 'title' => 'Gestionar reserva'],
            ],
        );
    }
}
