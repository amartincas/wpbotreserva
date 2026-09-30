<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Booking\Notifications\ResolvedProfessionalRecipient;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Fase 2B — reacción a BookingCancelled, versión profesional. Mismo
 * criterio que SendProfessionalBookingConfirmationNotification; ver ese
 * docblock para el razonamiento de ShouldQueueAfterCommit e independencia
 * del listener de cliente.
 */
class SendProfessionalBookingCancellationNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    private const TEMPLATE_NAME = 'reserva_cancelada_profesional';

    private const TEMPLATE_LANGUAGE = 'es';

    public function __construct(
        private readonly ProfessionalRecipientResolver $resolver,
        private readonly NotificationSenderInterface $sender,
        private readonly ProfessionalNotificationIdempotency $idempotency,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization', 'bookingResources.resource']);

        $recipient = $this->resolver->resolve($booking, 'cancellation');

        if ($recipient === null) {
            return;
        }

        $this->idempotency->onceFor(
            "cancellation:{$booking->id}",
            fn () => $this->sender->sendTemplate(
                $recipient->organization,
                $recipient->contactPhone,
                self::TEMPLATE_NAME,
                self::TEMPLATE_LANGUAGE,
                $this->bodyParameters($booking, $recipient),
            ),
        );
    }

    /**
     * @return string[]
     */
    private function bodyParameters(Booking $booking, ResolvedProfessionalRecipient $recipient): array
    {
        $localStartsAt = $booking->starts_at->setTimezone($recipient->organization->timezone);

        // {{5}} siempre se manda (Meta exige que el conteo de variables
        // coincida con el template aprobado) — vacío cuando no hay motivo,
        // nunca inventado (Diseño Fase 2B, sección 6).
        $reasonSuffix = $booking->cancellation_reason !== null
            ? " Motivo: {$booking->cancellation_reason}."
            : '';

        return [
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
            $localStartsAt->translatedFormat('d/m/Y'),
            $localStartsAt->translatedFormat('H:i'),
            $reasonSuffix,
        ];
    }
}
