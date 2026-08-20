<?php

namespace App\Console\Commands;

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\Booking;
use App\Enums\BookingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recordatorio al CLIENTE ~24h antes de su turno (Incremento 3) — distinto
 * de ReviewPastBookings (Incremento 2), que le avisa al DUEÑO sobre turnos
 * ya pasados sin resolver.
 *
 * Va por plantilla de Meta (recordatorio_reserva, categoría UTILITY) a
 * propósito: un recordatorio disparado ~24h antes del turno casi siempre
 * cae fuera de la ventana de 24h de conversación gratuita de WhatsApp (que
 * se abre con el ÚLTIMO mensaje del CLIENTE, no con este envío) — sin
 * plantilla aprobada, Meta rechaza el mensaje directamente.
 *
 * Corre cada hora con una ventana de 1h (ahora+23h a ahora+24h) para que,
 * sumado a la cadencia horaria, cada reserva caiga en su ventana una sola
 * vez — sin necesitar un rango más ancho que dispare reenvíos.
 */
class SendUpcomingBookingReminders extends Command
{
    protected $signature = 'bookings:send-upcoming-reminders';

    protected $description = 'Envía, por plantilla de Meta, un recordatorio al cliente ~24h antes de su turno';

    private const TEMPLATE_NAME = 'recordatorio_reserva';

    private const TEMPLATE_LANGUAGE = 'es';

    public function handle(NotificationSenderInterface $notifications): int
    {
        $bookings = Booking::where('status', BookingStatus::CONFIRMED)
            ->whereBetween('starts_at', [now()->addHours(23), now()->addHours(24)])
            ->whereNull('upcoming_reminder_sent_at')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $booking->loadMissing(['organization', 'service', 'customer']);

            try {
                $notifications->sendTemplate(
                    $booking->organization,
                    $booking->customer->phone->value(),
                    self::TEMPLATE_NAME,
                    self::TEMPLATE_LANGUAGE,
                    [
                        $booking->customer->name ?? 'cliente',
                        $booking->service->name,
                        $booking->organization->name,
                        $booking->starts_at->format('d/m/Y'),
                        $booking->starts_at->format('H:i'),
                    ],
                );
            } catch (NotificationDeliveryException $e) {
                // No se marca upcoming_reminder_sent_at si el envío realmente
                // falló — se reintenta en la próxima corrida horaria mientras
                // la reserva siga dentro de la ventana de 23-24h.
                Log::warning('SendUpcomingBookingReminders: falló el envío del recordatorio', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $booking->update(['upcoming_reminder_sent_at' => now()]);
            $sent++;
        }

        $this->info("Recordatorios de turno próximo enviados: {$sent}.");

        return self::SUCCESS;
    }
}
