<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use Illuminate\Support\Facades\Log;
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
          turno/cita nuevo como cliente de un negocio.
        - gestion_reserva: el mensaje indica que quien escribe quiere
          cancelar, reprogramar o consultar el estado de un turno/cita que
          ya tiene agendado (no uno nuevo).
        - fuera_de_alcance: cualquier otro caso.
        PROMPT;

    public function __construct(private readonly AiServiceInterface $ai) {}

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        // Mismo motivo que AiFieldExtractor: sin esto, una llamada lenta o
        // fallida acá es indistinguible de "el mensaje era genuinamente
        // ambiguo" — imposible de diagnosticar después de que pasó.
        $startedAt = microtime(true);

        try {
            $response = $this->ai->getResponse($message->text, self::SYSTEM_PROMPT, []);
        } catch (Throwable $e) {
            Log::warning('AiIntentClassifierStrategy: la llamada a la IA falló', [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($durationMs > 5000) {
            Log::warning('AiIntentClassifierStrategy: la llamada a la IA tardó más de lo esperado', [
                'duration_ms' => $durationMs,
            ]);
        }

        $intent = Intent::tryFrom(trim(strtolower($response)));

        if ($intent === null) {
            Log::warning('AiIntentClassifierStrategy: la IA respondió algo que no es un Intent válido', [
                'response' => $response,
            ]);
        }

        return $intent;
    }
}
