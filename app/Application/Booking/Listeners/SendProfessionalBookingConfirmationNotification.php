<?php

namespace App\Application\Booking\Listeners;

use App\Application\Booking\Notifications\ProfessionalNotificationIdempotency;
use App\Application\Booking\Notifications\ProfessionalRecipientResolver;
use App\Application\Booking\Notifications\ResolvedProfessionalRecipient;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Fase 2B — reacción a BookingConfirmed, versión profesional. Completamente
 * independiente de SendBookingConfirmationNotification (cliente, sin
 * cambios) — mismo evento, dos listeners registrados por separado en
 * AppServiceProvider (Diseño Fase 2B, sección 11).
 *
 * ShouldQueueAfterCommit (Diseño Fase 2B, sección 8): BookingConfirmed se
 * dispara DENTRO de la transacción de BookingScheduler::schedule(), y
 * queue.after_commit es false a nivel global — sin esto, el job podría
 * encolarse antes del commit. Costo cero, elimina la dependencia frágil de
 * que ningún acceso futuro a una relación no precargada corra antes del
 * commit.
 */
class SendProfessionalBookingConfirmationNotification implements ShouldQueue, ShouldQueueAfterCommit
{
    private const TEMPLATE_NAME = 'reserva_nueva_profesional';

    private const TEMPLATE_LANGUAGE = 'es';

    public function __construct(
        private readonly ProfessionalRecipientResolver $resolver,
        private readonly NotificationSenderInterface $sender,
        private readonly ProfessionalNotificationIdempotency $idempotency,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization', 'bookingResources.resource']);

        $recipient = $this->resolver->resolve($booking, 'confirmation');

        if ($recipient === null) {
            return;
        }

        $this->idempotency->onceFor(
            "confirmation:{$booking->id}",
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
        // Timezone de la Organization, nunca la del servidor (Diseño Fase
        // 2B, sección 7) — deliberadamente distinto del criterio que hoy
        // usan los 3 listeners de cliente (sin conversión explícita), que
        // no se tocan en esta fase.
        $localStartsAt = $booking->starts_at->setTimezone($recipient->organization->timezone);

        return [
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
            $localStartsAt->translatedFormat('d/m/Y'),
            $localStartsAt->translatedFormat('H:i'),
        ];
    }
}
