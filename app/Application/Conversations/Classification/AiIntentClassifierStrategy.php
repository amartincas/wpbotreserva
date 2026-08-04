<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use Throwable;

/**
 * Fallback final de la cadena — clasifica por contenido cuando no hay
 * continuidad de conversación. Depende de App\Contracts\AiServiceInterface
 * (la interfaz que las implementaciones concretas de app/Services/AI
 * realmente satisfacen — no App\Services\AI\AIServiceInterface, que quedó
 * huérfana/desalineada en el código de turismo; se detectó al construir
 * esta clase y se documenta acá para no repetir la confusión).
 *
 * Credenciales propias de la plataforma (config('services.intent_classifier')),
 * no las del negocio — WpbotReserva clasifica con su propia cuenta de IA,
 * nunca con la que un negocio pudiera traer.
 */
class AiIntentClassifierStrategy implements IntentClassifierStrategy
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Sos el clasificador de intención de WpbotReserva, una plataforma de
        reservas por WhatsApp para múltiples negocios. Dado un mensaje de un
        cliente, respondé EXCLUSIVAMENTE con una de estas palabras, sin
        puntuación ni explicación:

        - registro_negocio: el mensaje indica que quien escribe quiere dar de
          alta su propio negocio en la plataforma.
        - reserva: el mensaje indica que quien escribe quiere agendar un
          turno/cita como cliente de un negocio.
        - fuera_de_alcance: cualquier otro caso.
        PROMPT;

    public function __construct(private readonly AiServiceInterface $ai) {}

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        try {
            $response = $this->ai->getResponse($message->text, self::SYSTEM_PROMPT, []);
        } catch (Throwable) {
            return null;
        }

        return Intent::tryFrom(trim(strtolower($response)));
    }
}
