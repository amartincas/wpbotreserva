<?php

namespace App\Application\Booking\Listeners;

use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacción a BookingCancelled en la capa de Application (Parte XIII regla
 * 4), mismo patrón que SendBookingConfirmationNotification.
 */
class SendBookingCancellationNotification implements ShouldQueue
{
    public function __construct(private readonly NotificationSenderInterface $sender) {}

    public function handle(BookingCancelled $event): void
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
            "❌ Tu reserva fue cancelada.\n\n%s\n📅 %s\n\nSi querés agendar un nuevo turno, escríbenos por acá.",
            $booking->service->name,
            $booking->starts_at->translatedFormat('l d/m/Y H:i'),
        );
    }
}
