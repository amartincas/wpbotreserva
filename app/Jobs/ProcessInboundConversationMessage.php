<?php

namespace App\Jobs;

use App\Application\Conversations\InboundMessageRouter;
use App\Domain\Conversational\InboundMessage;
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
 * arriba no protege contra esto, solo contra paralelismo). Se usa
 * Cache::add() (SETNX atómico) en vez de ShouldBeUnique de Laravel a
 * propósito: ShouldBeUnique libera su lock en cuanto el job TERMINA de
 * procesarse, no durante toda la ventana configurada — un reenvío de Meta
 * que llegue después de que el primer job ya completó (el caso más común,
 * dado que procesar un mensaje toma segundos) no quedaría cubierto. El
 * marcador de Cache::add() sí persiste durante los $dedupHours completos,
 * independientemente de cuánto tardó el procesamiento original.
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

    public function handle(InboundMessageRouter $router): void
    {
        $dedupKey = "inbound_message_processed:{$this->message->phoneNumberId}:{$this->message->messageId}";
        $dedupHours = $this->dedupHours ?? config('conversations.message_dedup_hours');

        if (! Cache::add($dedupKey, true, now()->addHours($dedupHours))) {
            return;
        }

        $lockKey = "conversation:{$this->message->phoneNumberId}:{$this->message->fromPhone}";

        Cache::lock($lockKey, $this->lockSeconds)->block($this->blockSeconds, function () use ($router) {
            $router->handle($this->message);
        });
    }
}
