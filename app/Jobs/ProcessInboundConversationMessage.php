<?php

namespace App\Jobs;

use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Conversations\InboundMessageRouter;
use App\Domain\Conversational\InboundMessage;
use App\Models\ConversationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Envoltorio de ejecución segura alrededor de InboundMessageRouter (validado
 * antes del Hito 4): adquiere un mutex de Redis por conversación ANTES de
 * invocar al Router, para que nunca se procesen dos mensajes de la misma
 * conversación en paralelo. Clave = phone_number_id + fromPhone (no
 * channel_id — el Channel recién se resuelve dentro del Router; usar el
 * identificador crudo del webhook evita depender de esa resolución acá).
 *
 * Deduplicación por message_id (enmienda post-Hito 4): Meta puede reintentar
 * la entrega del webhook para el mismo mensaje — sin esto se procesaría dos
 * veces, aunque ya haya terminado de procesarse la primera vez (el mutex de
 * arriba no protege contra esto, solo contra paralelismo). El marcador de
 * dedup persiste durante los $dedupHours completos vía Cache::put(),
 * independientemente de cuánto tardó el procesamiento original.
 *
 * El marcador se escribe recién DESPUÉS de que el Router termina sin
 * excepción, y siempre dentro de la misma sección crítica que ya protege
 * el mutex de arriba (nunca antes de intentar el trabajo real) — bug real
 * encontrado en el Hito 8: la versión original usaba Cache::add() ANTES de
 * ejecutar el Router para reclamar la clave, así que una falla transitoria
 * en el primer intento (ej. un timeout de la IA) dejaba la clave reclamada
 * para siempre sin que el trabajo real se hubiera completado — los
 * reintentos automáticos de este mismo Job ($tries=3 abajo) chocaban con
 * su propia clave y retornaban de inmediato como si hubieran tenido éxito,
 * sin ejecutar el Router una segunda vez. Afectó tanto al registro de un
 * negocio piloto real (confirmación trabada sin ningún error visible)
 * como, más tarde, a una reserva real (un mensaje del cliente se perdió en
 * silencio en medio del intercambio). Ya no se usa Cache::add() porque el
 * chequeo y la escritura ahora comparten la misma sección crítica del
 * mutex — no hace falta una operación atómica aparte.
 *
 * La clave incluye phoneNumberId (no solo messageId): no se asume que el
 * identificador de mensaje sea único de forma global entre proveedores —
 * es una garantía real para Meta (WAMID), pero un futuro proveedor
 * (Telegram, por ejemplo) tiene message_id único solo dentro de un chat,
 * no global. Mismo criterio que la clave del mutex de arriba.
 *
 * Esto vive en Redis — no sobrevive un reinicio de infraestructura ni cubre
 * reintentos más allá de la ventana configurada; si eso llega a ser un
 * problema real, la evolución identificada es persistir mensajes procesados
 * en BD como defensa adicional (que además podría servir de base para la
 * bitácora de auditoría append-only pospuesta en Parte IV) — no se
 * construye ahora, sin evidencia de que haga falta.
 *
 * El disparo real desde el webhook de WhatsApp es Hito 7 — esta clase ya
 * queda lista para que ese hito solo tenga que dispatch()earla.
 */
class ProcessInboundConversationMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly InboundMessage $message,
        private readonly int $lockSeconds = 30,
        private readonly int $blockSeconds = 10,
        private readonly ?int $dedupHours = null,
    ) {}

    public function handle(InboundMessageRouter $router, ChannelResolverInterface $channels, ConversationSessionRepositoryInterface $sessions): void
    {
        $dedupKey = "inbound_message_processed:{$this->message->phoneNumberId}:{$this->message->messageId}";
        $dedupHours = $this->dedupHours ?? config('conversations.message_dedup_hours');
        $lockKey = "conversation:{$this->message->phoneNumberId}:{$this->message->fromPhone}";

        Cache::lock($lockKey, $this->lockSeconds)->block($this->blockSeconds, function () use ($router, $channels, $sessions, $dedupKey, $dedupHours) {
            if (Cache::has($dedupKey)) {
                return;
            }

            $router->handle($this->message);

            $this->logInboundMessage($channels, $sessions);

            Cache::put($dedupKey, true, now()->addHours($dedupHours));
        });
    }

    /**
     * Post-E2E Fase 1 (Hallazgo 5): trazabilidad de observabilidad, nunca
     * parte de la lógica de negocio — corre DESPUÉS de que el Router ya
     * resolvió Channel/Organization/ConversationSession, así que solo lee
     * ese estado ya resuelto (mismos resolvers, sin duplicar lógica de
     * resolución) en vez de recalcularlo. No corre para reentregas
     * deduplicadas (el "return" de arriba las corta antes de llegar acá) —
     * evita filas duplicadas para el mismo message_id sin necesitar un
     * constraint dedicado.
     */
    private function logInboundMessage(ChannelResolverInterface $channels, ConversationSessionRepositoryInterface $sessions): void
    {
        $channel = $channels->resolve($this->message->phoneNumberId);
        $organizationId = null;

        if ($channel !== null) {
            $organizationId = $sessions->findOrCreateFor($channel, $this->message->fromPhone)->organization_id;
        }

        ConversationMessage::create([
            'channel_id' => $channel?->id,
            'organization_id' => $organizationId,
            'customer_phone' => $this->message->fromPhone,
            'direction' => 'inbound',
            'message_id' => $this->message->messageId,
            'body' => $this->message->text,
            'created_at' => $this->message->receivedAt,
        ]);
    }
}
