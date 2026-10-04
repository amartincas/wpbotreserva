<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Contracts\OwnerNotifierInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Reacción a BookingConfirmed del lado del negocio — avisa al OWNER
 * (Organization.owner_phone) por el número CENTRAL (B6; antes, Fase 2B,
 * avisaba al profesional por el teléfono propio del Resource). Completamente
 * independiente de SendBookingConfirmationNotification (cliente, sin
 * cambios) — mismo evento, dos listeners registrados por separado en
 * AppServiceProvider.
 *
 * ShouldQueueAfterCommit (Diseño Fase 2B, sección 8): BookingConfirmed se
 * dispara DENTRO de la transacción de BookingScheduler::schedule(), y
 * queue.after_commit es false a nivel global — sin esto, el job podría
 * encolarse antes del commit.
 *
 * Sin owner_phone no hay a quién avisar: se loguea y no se reintenta. Un
 * fallo de envío (sin CENTRAL activo, error de Meta) se deja propagar para
 * que la cola reintente; la idempotencia evita duplicados entre reintentos.
 */
class SendOwnerBookingConfirmationNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    private const TEMPLATE_NAME = 'reserva_nueva_profesional';

    private const TEMPLATE_LANGUAGE = 'es';

    public function __construct(
        private readonly OwnerNotifierInterface $owner,
        private readonly ProfessionalNotificationIdempotency $idempotency,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization']);

        if ($booking->organization->owner_phone === null) {
            Log::warning('SendOwnerBookingConfirmationNotification: la Organization no tiene owner_phone', [
                'booking_id' => $booking->id,
                'organization_id' => $booking->organization_id,
            ]);

            return;
        }

        $this->idempotency->onceFor(
            "confirmation:{$booking->id}",
            fn () => $this->owner->sendTemplate(
                $booking->organization,
                self::TEMPLATE_NAME,
                self::TEMPLATE_LANGUAGE,
                $this->bodyParameters($booking),
            ),
        );
    }

    /**
     * @return string[]
     */
    private function bodyParameters(Booking $booking): array
    {
        // Timezone de la Organization, nunca la del servidor (Diseño Fase
        // 2B, sección 7).
        $localStartsAt = $booking->starts_at->setTimezone($booking->organization->timezone);

        return [
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
            $localStartsAt->translatedFormat('d/m/Y'),
            $localStartsAt->translatedFormat('H:i'),
        ];
    }
}
