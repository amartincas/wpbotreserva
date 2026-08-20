<?php

namespace App\Application\Booking\Listeners;

use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Events\BookingRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacción a BookingRescheduled en la capa de Application (Parte XIII regla
 * 4), mismo patrón que SendBookingConfirmationNotification.
 */
class SendBookingRescheduleNotification implements ShouldQueue
{
    public function __construct(private readonly NotificationSenderInterface $sender) {}

    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing(['service', 'customer', 'organization']);

        $this->sender->send(
            $booking->organization,
            $booking->customer->phone->value(),
            $this->buildMessage($booking, $event->previousStartsAt),
        );
    }

    private function buildMessage(Booking $booking, CarbonImmutable $previousStartsAt): string
    {
        return sprintf(
            "🔄 Tu reserva fue reprogramada.\n\n%s\nAntes: %s\nAhora: %s",
            $booking->service->name,
            $previousStartsAt->translatedFormat('l d/m/Y H:i'),
            $booking->starts_at->translatedFormat('l d/m/Y H:i'),
        );
    }
}
