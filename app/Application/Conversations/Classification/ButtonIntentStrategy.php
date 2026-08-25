<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;

/**
 * Reconoce los botones del menú inicial de OutOfScopeAgent — coincidencia
 * exacta de id, nunca IA (mismo criterio que DeterministicAdminCommandStrategy
 * y ResetKeywordStrategy): el cliente ya eligió una opción de un conjunto
 * cerrado que el propio sistema le ofreció, no hay nada que interpretar.
 *
 * Corre ANTES que ConversationContinuityStrategy a propósito: el mensaje
 * anterior (el que disparó el menú) quedó clasificado como FueraDeAlcance
 * y ese Intent ya se grabó en la sesión — sin este orden,
 * ConversationContinuityStrategy repetiría FueraDeAlcance de nuevo en vez
 * de dejar que el click del botón reclasifique correctamente.
 *
 * Los botones DENTRO de un flujo activo (sí/no, elegir servicio, elegir
 * acción de gestión) no necesitan esta ni ninguna otra estrategia nueva:
 * su id ya es exactamente la palabra que el Agent dueño del flujo sabe
 * interpretar (ver FlowStep/handleXxx de cada Agent), así que
 * ConversationContinuityStrategy los deja pasar sin tocarlos.
 */
class ButtonIntentStrategy implements IntentClassifierStrategy
{
    private const MAP = [
        'menu_registro_negocio' => Intent::RegistroNegocio,
        'menu_reserva' => Intent::Reserva,
        'menu_gestion_reserva' => Intent::GestionReserva,
    ];

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        return self::MAP[trim($message->text)] ?? null;
    }
}
