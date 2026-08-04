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
    ) {}

    public function handle(InboundMessageRouter $router): void
    {
        $lockKey = "conversation:{$this->message->phoneNumberId}:{$this->message->fromPhone}";

        Cache::lock($lockKey, $this->lockSeconds)->block($this->blockSeconds, function () use ($router) {
            $router->handle($this->message);
        });
    }
}
