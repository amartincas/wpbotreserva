<?php

namespace App\Application\Booking\Listeners;

use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacción a BookingConfirmed en la capa de Application (Parte XIII regla
 * 4) — el Domain Event no sabe que esto existe. Encolado: un fallo o
 * lentitud de la API de Meta no debe demorar la transacción que confirmó
 * la reserva.
 */
class SendBookingConfirmationNotification implements ShouldQueue
{
    public function __construct(private readonly NotificationSenderInterface $sender) {}

    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization']);

        $this->sender->send(
            $booking->organization,
            $booking->customer->phone->value(),
            $this->buildMessage($booking),
        );
    }

    private function buildMessage(Booking $booking): string
    {
        return sprintf(
            "✅ ¡Reserva confirmada!\n\n%s\n📅 %s\n\nSi necesitas cancelar o reprogramar, escríbenos por acá.",
            $booking->service->name,
            $booking->starts_at->translatedFormat('l d/m/Y H:i'),
        );
    }
}
