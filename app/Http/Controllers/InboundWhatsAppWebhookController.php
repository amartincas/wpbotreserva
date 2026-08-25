<?php

namespace App\Http\Controllers;

use App\Domain\Conversational\InboundMessage;
use App\Jobs\ProcessInboundConversationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook propio de WpbotReserva — separado del webhook del bot de turismo
 * (api/whatsapp/webhook, WhatsAppController), número/App de Meta distinto.
 * Responsabilidad deliberadamente mínima (Parte XVI: "el Webhook solo
 * entrega el mensaje — cero resolución en el controller"): parsea el
 * payload crudo de Meta a InboundMessage y despacha
 * ProcessInboundConversationMessage — nunca resuelve Channel/Organization
 * ni toca el Router directamente, eso vive dentro del Job.
 *
 * Alcance del Hito 7: solo mensajes de texto. Audio/imagen/ubicación se
 * ignoran silenciosamente (se cuentan como "sin texto", ver extractText())
 * — no hay ningún hito que los soporte todavía; es un límite explícito, no
 * un olvido. Los botones/listas interactivas SÍ se soportan (agregado para
 * las respuestas de opción cerrada — ver ChannelClientInterface::sendButtonsMessage()):
 * el $id que se mandó al enviar el botón vuelve acá como si fuera el texto
 * tipeado por el cliente, así que todo el pipeline de abajo (clasificador,
 * agentes) nunca necesita saber que existió un botón.
 */
class InboundWhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->input('hub_mode');
        $verifyToken = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $this->verifyTokenMatches($verifyToken)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response('Forbidden', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $this->processChange($change['value'] ?? []);
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function processChange(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if ($phoneNumberId === null) {
            return;
        }

        foreach ($value['messages'] ?? [] as $rawMessage) {
            // Un mensaje individual con forma inesperada no debe tirar abajo
            // el resto del batch (Meta puede agrupar varios entry/changes en
            // un solo POST) — entrada externa no confiable, se loguea y se
            // sigue, no es un caso "que no puede pasar".
            try {
                $message = $this->toInboundMessage($rawMessage, $phoneNumberId);

                if ($message !== null) {
                    ProcessInboundConversationMessage::dispatch($message);
                }
            } catch (Throwable $e) {
                Log::warning('InboundWhatsAppWebhookController: no se pudo procesar un mensaje del payload', [
                    'error' => $e->getMessage(),
                    'raw_message' => $rawMessage,
                ]);
            }
        }
    }

    private function toInboundMessage(array $rawMessage, string $phoneNumberId): ?InboundMessage
    {
        $text = $this->extractText($rawMessage);

        if ($text === null || ! isset($rawMessage['id'], $rawMessage['from'])) {
            return null;
        }

        return new InboundMessage(
            messageId: $rawMessage['id'],
            phoneNumberId: $phoneNumberId,
            fromPhone: '+'.ltrim($rawMessage['from'], '+'),
            text: $text,
            receivedAt: isset($rawMessage['timestamp'])
                ? CarbonImmutable::createFromTimestamp((int) $rawMessage['timestamp'])
                : now()->toImmutable(),
        );
    }

    private function extractText(array $rawMessage): ?string
    {
        return match ($rawMessage['type'] ?? null) {
            'text' => $rawMessage['text']['body'] ?? null,
            'interactive' => $this->extractInteractiveReplyId($rawMessage),
            default => null,
        };
    }

    /**
     * El id de un botón o de una opción de lista viaja tal cual — nunca el
     * title visible — porque es lo que las estrategias de clasificación y
     * los agentes ya saben interpretar (mismos valores que se mandaron al
     * enviar el mensaje interactivo, ej. "si"/"no"/"nueva"/"cancelar").
     */
    private function extractInteractiveReplyId(array $rawMessage): ?string
    {
        $interactive = $rawMessage['interactive'] ?? [];

        return match ($interactive['type'] ?? null) {
            'button_reply' => $interactive['button_reply']['id'] ?? null,
            'list_reply' => $interactive['list_reply']['id'] ?? null,
            default => null,
        };
    }

    private function verifyTokenMatches(?string $token): bool
    {
        $expected = config('services.whatsapp_webhook.verify_token');

        return $expected !== null && $token !== null && hash_equals($expected, $token);
    }

    private function hasValidSignature(Request $request): bool
    {
        $appSecret = config('services.whatsapp_webhook.app_secret');

        if (! $appSecret) {
            // Sin app_secret configurado no hay forma de validar — mismo
            // estado que el webhook legado hoy (sin verificación de firma).
            // Gap explícito, documentado en config/services.php, no una
            // omisión silenciosa.
            return true;
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
