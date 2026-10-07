<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Contracts\OwnerNotifierInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Reacción a BookingCancelled del lado del negocio — avisa al OWNER por el
 * CENTRAL (B6). Mismo criterio que SendOwnerBookingConfirmationNotification;
 * ver ese docblock para ShouldQueueAfterCommit, el caso sin owner_phone y
 * los reintentos.
 */
class SendOwnerBookingCancellationNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    private const TEMPLATE_NAME = 'reserva_cancelada_profesional';

    private const TEMPLATE_LANGUAGE = 'es';

    private const NO_REASON = 'Sin motivo';

    public function __construct(
        private readonly OwnerNotifierInterface $owner,
        private readonly ProfessionalNotificationIdempotency $idempotency,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization']);

        if ($booking->organization->owner_phone === null) {
            Log::warning('SendOwnerBookingCancellationNotification: la Organization no tiene owner_phone', [
                'booking_id' => $booking->id,
                'organization_id' => $booking->organization_id,
            ]);

            return;
        }

        $this->idempotency->onceFor(
            "cancellation:{$booking->id}",
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
        $localStartsAt = $booking->starts_at->setTimezone($booking->organization->timezone);

        return [
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
            $localStartsAt->translatedFormat('d/m/Y'),
            $localStartsAt->translatedFormat('H:i'),
            $this->reasonParameter($booking->cancellation_reason),
        ];
    }

    /**
     * {{5}} siempre se manda (Meta exige que el conteo de variables coincida
     * con el template aprobado) y nunca vacío: Meta rechaza parámetros
     * vacíos. El template ya trae "Motivo: {{5}}.", así que acá va solo el
     * texto. El motivo lo escribe el cliente libremente — se colapsan saltos
     * de línea, tabs y espacios repetidos (Meta rechaza el parámetro con
     * error 132018) y se quita el punto final para no duplicarlo.
     */
    private function reasonParameter(?string $reason): string
    {
        $normalized = rtrim(trim(preg_replace('/\s+/u', ' ', $reason ?? '')), '.');

        return $normalized !== '' ? $normalized : self::NO_REASON;
    }
}
