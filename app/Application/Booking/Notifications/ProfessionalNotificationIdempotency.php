<?php

namespace App\Application\Booking\Notifications;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Fase 2B — idempotencia exclusiva para notificaciones al profesional
 * (Diseño Fase 2B, sección 9). Mismo primitivo ya probado en producción por
 * ProcessInboundConversationMessage (Cache::lock()->block() + marcador
 * Cache::has/put) — nunca conversation_messages, que es observabilidad
 * pura y no tiene booking_id.
 *
 * $occurrenceKey lo arma cada listener, no este componente — confirmation/
 * cancellation pueden usar solo booking_id (transición única en el flujo
 * actual), pero reschedule NECESITA booking_id + horario anterior + horario
 * nuevo (una misma reserva puede reprogramarse varias veces de forma
 * legítima; una clave solo por booking_id trataría la 2da reprogramación
 * real como un duplicado de la 1ra).
 *
 * Mismo criterio ya aprendido en este proyecto (documentado en
 * ProcessInboundConversationMessage): el marcador se escribe DESPUÉS de que
 * $send() termina sin excepción, dentro de la misma sección crítica del
 * lock — nunca antes. Si $send() lanza, el marcador no se escribe y un
 * reintento posterior sí vuelve a intentar el envío real.
 */
final class ProfessionalNotificationIdempotency
{
    private const LOCK_SECONDS = 15;

    private const BLOCK_SECONDS = 10;

    private const MARKER_TTL_HOURS = 48;

    public function onceFor(string $occurrenceKey, Closure $send): void
    {
        $markerKey = "professional_notification_sent:{$occurrenceKey}";
        $lockKey = "professional_notification_lock:{$occurrenceKey}";

        Cache::lock($lockKey, self::LOCK_SECONDS)->block(self::BLOCK_SECONDS, function () use ($markerKey, $send) {
            if (Cache::has($markerKey)) {
                return;
            }

            $send();

            Cache::put($markerKey, true, now()->addHours(self::MARKER_TTL_HOURS));
        });
    }
}
