<?php

namespace App\Console\Commands;

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Booking\Booking;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Enums\BookingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recordatorio al CLIENTE ~24h antes de su turno (Incremento 3) — distinto
 * de ReviewPastBookings (Incremento 2), que le avisa al DUEÑO sobre turnos
 * ya pasados sin resolver.
 *
 * Fase 3: el recordatorio ahora pregunta explícitamente por la asistencia
 * (confirmacion_asistencia_reserva, con botones Sí/No — reemplaza al
 * anterior recordatorio puramente informativo). Tras un envío exitoso,
 * además de marcar upcoming_reminder_sent_at, crea la fila
 * PendingAttendanceConfirmation que correlaciona la próxima respuesta del
 * cliente con este Booking (ver PendingAttendanceConfirmationStrategy) —
 * nunca si el envío falla, mismo criterio que ya usa
 * upcoming_reminder_sent_at.
 *
 * Va por plantilla de Meta (categoría UTILITY) a propósito: un recordatorio
 * disparado ~24h antes del turno casi siempre cae fuera de la ventana de
 * 24h de conversación gratuita de WhatsApp (que se abre con el ÚLTIMO
 * mensaje del CLIENTE, no con este envío) — sin plantilla aprobada, Meta
 * rechaza el mensaje directamente.
 *
 * Corre cada hora con una ventana de 1h (ahora+23h a ahora+24h) para que,
 * sumado a la cadencia horaria, cada reserva caiga en su ventana una sola
 * vez — sin necesitar un rango más ancho que dispare reenvíos.
 */
class SendUpcomingBookingReminders extends Command
{
    protected $signature = 'bookings:send-upcoming-reminders';

    protected $description = 'Envía, por plantilla de Meta, un recordatorio de asistencia al cliente ~24h antes de su turno';

    private const TEMPLATE_NAME = 'confirmacion_asistencia_reserva';

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
                        $booking->service->name,
                        $booking->starts_at->format('d/m/Y'),
                        $booking->starts_at->format('H:i'),
                    ],
                );
            } catch (NotificationDeliveryException $e) {
                // No se marca upcoming_reminder_sent_at ni se crea la fila
                // pendiente si el envío realmente falló — se reintenta en
                // la próxima corrida horaria mientras la reserva siga
                // dentro de la ventana de 23-24h.
                Log::warning('SendUpcomingBookingReminders: falló el envío del recordatorio', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // sendTemplate() ya tuvo éxito acá arriba, deliberadamente FUERA
            // de esta transacción (nunca envolver una llamada de red en una
            // transacción de BD). upcoming_reminder_sent_at y la fila
            // pendiente se escriben como una sola unidad: si cualquiera de
            // las dos falla, ambas se revierten — dejar solo una de las dos
            // aplicada es peor que no haber aplicado ninguna (una reserva
            // marcada "ya recordada" sin fila pendiente queda incorrelable
            // para siempre, ver revisión pre-commit).
            try {
                DB::transaction(function () use ($booking) {
                    $booking->update(['upcoming_reminder_sent_at' => now()]);

                    PendingAttendanceConfirmation::create([
                        'booking_id' => $booking->id,
                        'template_name' => self::TEMPLATE_NAME,
                        'expires_at' => $booking->starts_at,
                    ]);
                });
            } catch (Throwable $e) {
                // Nunca propagar: un fallo de persistencia en esta reserva
                // no debe abortar el resto del lote. upcoming_reminder_sent_at
                // queda en NULL (rollback), así que la próxima corrida la
                // vuelve a intentar completa (envío incluido).
                Log::error('SendUpcomingBookingReminders: falló la persistencia posterior al envío exitoso', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $sent++;
        }

        $this->info("Recordatorios de turno próximo enviados: {$sent}.");

        return self::SUCCESS;
    }
}
