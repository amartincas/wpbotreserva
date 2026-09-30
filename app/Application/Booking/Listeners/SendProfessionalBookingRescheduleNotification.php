<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Booking\Notifications\ResolvedProfessionalRecipient;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Fase 2B — reacción a BookingRescheduled, versión profesional. Mismo
 * criterio general que los otros 2 listeners de Fase 2B (ver
 * SendProfessionalBookingConfirmationNotification), con una diferencia
 * deliberada en la clave de idempotencia:
 *
 * Una misma reserva puede reprogramarse legítimamente más de una vez — una
 * clave basada solo en booking_id trataría la 2da reprogramación real como
 * un duplicado de la 1ra y jamás la notificaría. La clave identifica la
 * OCURRENCIA concreta (booking_id + horario anterior + horario nuevo), no
 * la reserva en sí — así, repetir exactamente el mismo evento (mismo
 * movimiento de horario, ej. un reintento de cola) no reenvía, pero un
 * movimiento distinto sí.
 */
class SendProfessionalBookingRescheduleNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    private const TEMPLATE_NAME = 'reserva_modificada_profesional';

    private const TEMPLATE_LANGUAGE = 'es';

    public function __construct(
        private readonly ProfessionalRecipientResolver $resolver,
        private readonly NotificationSenderInterface $sender,
        private readonly ProfessionalNotificationIdempotency $idempotency,
    ) {}

    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization', 'bookingResources.resource']);

        $recipient = $this->resolver->resolve($booking, 'reschedule');

        if ($recipient === null) {
            return;
        }

        $previousStartsAt = $event->previousStartsAt;

        $occurrenceKey = sprintf(
            'reschedule:%d:%s:%s',
            $booking->id,
            $previousStartsAt->toIso8601String(),
            $booking->starts_at->toIso8601String(),
        );

        $this->idempotency->onceFor(
            $occurrenceKey,
            fn () => $this->sender->sendTemplate(
                $recipient->organization,
                $recipient->contactPhone,
                self::TEMPLATE_NAME,
                self::TEMPLATE_LANGUAGE,
                $this->bodyParameters($booking, $previousStartsAt, $recipient),
            ),
        );
    }

    /**
     * @return string[]
     */
    private function bodyParameters(Booking $booking, CarbonImmutable $previousStartsAt, ResolvedProfessionalRecipient $recipient): array
    {
        $localPrevious = $previousStartsAt->setTimezone($recipient->organization->timezone);
        $localNew = $booking->starts_at->setTimezone($recipient->organization->timezone);

        return [
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
            $localPrevious->translatedFormat('l d/m/Y H:i'),
            $localNew->translatedFormat('l d/m/Y H:i'),
        ];
    }
}
