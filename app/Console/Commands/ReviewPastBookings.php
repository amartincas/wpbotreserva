<?php

namespace App\Console\Commands;

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
use App\Enums\BookingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierra el gap real señalado en el Incremento 2: sin esto, una reserva
 * CONFIRMED cuya fecha ya pasó queda así para siempre — nada la
 * transiciona sola, así que GestionReservaAgent la sigue ofreciendo como
 * "activa" indefinidamente.
 *
 * Dos pasos, en cada corrida diaria:
 * 1. Recordatorio (una sola vez por reserva, vía reminder_sent_at): le
 *    avisa al dueño que un turno vencido no se resolvió, pidiéndole
 *    "ausente <id>" solo si el cliente no vino — reutiliza el comando
 *    admin que ya existe en vez de inventar un mecanismo de conversación
 *    nuevo. No hacer nada es una respuesta válida (significa "sí pasó").
 * 2. Respaldo a los 7 días: si el dueño nunca respondió, se completa solo
 *    (asume el caso más común — el turno se realizó pero nadie se tomó el
 *    trabajo de confirmarlo) y se le avisa al dueño que pasó, con
 *    instrucciones explícitas de cómo revertirlo si en realidad fue un
 *    no-show ("ausente <id>" también acepta una reserva ya COMPLETED,
 *    justamente para este caso).
 */
class ReviewPastBookings extends Command
{
    private const REMINDER_TEMPLATE = 'aviso_turno_vencido';

    private const AUTO_COMPLETED_TEMPLATE = 'turno_completado_automatico';

    private const TEMPLATE_LANGUAGE = 'es';

    protected $signature = 'bookings:review-past';

    protected $description = 'Recuerda al dueño turnos vencidos sin resolver y completa por defecto los que llevan más de 7 días sin respuesta';

    public function handle(BookingSchedulerInterface $scheduler, NotificationSenderInterface $notifications): int
    {
        $reminded = $this->sendReminders($notifications);
        $completed = $this->autoCompleteStale($scheduler, $notifications);

        $this->info("Recordatorios enviados: {$reminded}. Completadas automáticamente: {$completed}.");

        return self::SUCCESS;
    }

    /**
     * Excluye explícitamente las que ya califican para el respaldo de 7 días
     * (ver autoCompleteStale) — sin esto, una reserva que recién ahora se ve
     * por primera vez pero ya tiene 8+ días de vencida recibiría un
     * recordatorio Y, en la misma corrida, el aviso de auto-completado
     * inmediatamente después. En operación normal (corriendo a diario desde
     * el día 1) esto no pasa nunca, porque reminder_sent_at ya estaría
     * seteado bastante antes del día 7 — pero no vale la pena depender de
     * eso para que el mensaje sea coherente.
     */
    private function sendReminders(NotificationSenderInterface $notifications): int
    {
        $bookings = Booking::where('status', BookingStatus::CONFIRMED)
            ->where('ends_at', '<', now())
            ->where('ends_at', '>=', now()->subDays(7))
            ->whereNull('reminder_sent_at')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $booking->loadMissing(['organization', 'service', 'customer']);
            $ownerPhone = $booking->organization->owner_phone;

            if ($ownerPhone === null) {
                continue;
            }

            try {
                $notifications->sendTemplate(
                    $booking->organization,
                    $ownerPhone,
                    self::REMINDER_TEMPLATE,
                    self::TEMPLATE_LANGUAGE,
                    $this->bodyParameters($booking),
                );
            } catch (NotificationDeliveryException $e) {
                // No se marca reminder_sent_at si el envío realmente falló
                // (mismo criterio que el Bug #3 de esta bitácora: nunca
                // marcar "hecho" algo que no se completó de verdad) — se
                // reintenta en la próxima corrida diaria.
                Log::warning('ReviewPastBookings: falló el envío del recordatorio', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $booking->update(['reminder_sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    private function autoCompleteStale(BookingSchedulerInterface $scheduler, NotificationSenderInterface $notifications): int
    {
        $bookings = Booking::where('status', BookingStatus::CONFIRMED)
            ->where('ends_at', '<', now()->subDays(7))
            ->get();

        $completed = 0;

        foreach ($bookings as $booking) {
            $booking->loadMissing(['organization', 'service', 'customer']);

            try {
                $scheduler->complete($booking);
            } catch (BookingAlreadyTerminalException) {
                // Carrera con una cancelación/ausente entre el SELECT y este
                // punto — se salta, no interrumpe el resto del lote.
                continue;
            }

            $completed++;

            $ownerPhone = $booking->organization->owner_phone;
            if ($ownerPhone === null) {
                continue;
            }

            // La transición de estado ya se aplicó — un fallo de envío acá
            // no debe revertirla ni interrumpir el resto del lote, solo se
            // registra. El dueño puede ver el resultado igual con "reservas
            // dd/mm/aaaa".
            try {
                $notifications->sendTemplate(
                    $booking->organization,
                    $ownerPhone,
                    self::AUTO_COMPLETED_TEMPLATE,
                    self::TEMPLATE_LANGUAGE,
                    $this->bodyParameters($booking),
                );
            } catch (NotificationDeliveryException $e) {
                Log::warning('ReviewPastBookings: falló el aviso de auto-completado', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $completed;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function bodyParameters(Booking $booking): array
    {
        return [
            $booking->customer->name ?? $booking->customer->phone->value(),
            $booking->service->name,
            $booking->starts_at->translatedFormat('l d/m/Y H:i'),
            (string) $booking->id,
        ];
    }
}
