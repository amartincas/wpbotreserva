<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\ConfirmBookingCommand;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Comandos administrativos deterministas para el dueño del negocio
 * (Incremento 2, Parte VII) — sin FlowStep/draft: cada comando llega
 * completo en un único mensaje (DeterministicAdminCommandStrategy ya
 * garantizó el match exacto antes de clasificar Intent::AdminCommand), así
 * que no hay estado que mantener entre turnos.
 *
 * `$organization->bookings()->find($id)` (no Booking::find()) es lo que
 * hace que "cancelar <id>" nunca pueda tocar la reserva de OTRO negocio —
 * un ID que no pertenece a esta Organization simplemente no aparece, ni
 * siquiera como error distinto de "no existe".
 */
class AdminCommandAgent implements AgentInterface
{
    public function __construct(
        private readonly NotificationSenderInterface $notifications,
        private readonly CancelBookingCommand $cancelBooking,
        private readonly ConfirmBookingCommand $confirmBooking,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $text = trim($message->text);

        if (preg_match('/^reservas\s+hoy$/iu', $text)) {
            $this->listForDate($organization, $message->fromPhone, now()->toImmutable(), 'hoy');

            return;
        }

        if (preg_match('/^reservas\s+(\d{1,2})\/(\d{1,2})\/(\d{4})$/iu', $text, $matches)) {
            $this->listForRequestedDate($organization, $message->fromPhone, (int) $matches[1], (int) $matches[2], (int) $matches[3]);

            return;
        }

        if (preg_match('/^cancelar\s+(\d+)$/iu', $text, $matches)) {
            $this->cancel($organization, $message->fromPhone, (int) $matches[1]);

            return;
        }

        if (preg_match('/^confirmar\s+(\d+)$/iu', $text, $matches)) {
            $this->confirm($organization, $message->fromPhone, (int) $matches[1]);

            return;
        }

        // No debería ocurrir: DeterministicAdminCommandStrategy ya validó el
        // mismo patrón antes de clasificar Intent::AdminCommand. Devuelve un
        // mensaje en vez de romper si algún día ese contrato se desalinea.
        $this->reply($organization, $message->fromPhone, 'Comando no reconocido.');
    }

    /**
     * checkdate() (no Carbon::createFromFormat) a propósito: Carbon es
     * permisivo con fechas fuera de rango (ej. 31/02 rueda a marzo) —
     * checkdate() es la validación de calendario real de PHP, exactamente
     * lo que hace falta antes de aceptar una fecha que un dueño tipeó a mano.
     */
    private function listForRequestedDate(Organization $organization, string $toPhone, int $day, int $month, int $year): void
    {
        if (! checkdate($month, $day, $year)) {
            $this->reply($organization, $toPhone, 'Esa fecha no es válida. Usá el formato dd/mm/aaaa.');

            return;
        }

        $date = CarbonImmutable::create($year, $month, $day);

        $this->listForDate($organization, $toPhone, $date, $date->format('d/m/Y'));
    }

    private function listForDate(Organization $organization, string $toPhone, CarbonImmutable $date, string $label): void
    {
        $bookings = $organization->bookings()
            ->whereDate('starts_at', $date->toDateString())
            ->orderBy('starts_at')
            ->get();

        if ($bookings->isEmpty()) {
            $this->reply($organization, $toPhone, "No tenés reservas para {$label}.");

            return;
        }

        $this->reply($organization, $toPhone, "Reservas de {$label}:\n\n".$this->formatBookingList($bookings));
    }

    private function cancel(Organization $organization, string $toPhone, int $bookingId): void
    {
        $booking = $organization->bookings()->find($bookingId);

        if ($booking === null) {
            $this->reply($organization, $toPhone, "No encontré la reserva #{$bookingId}.");

            return;
        }

        try {
            $this->cancelBooking->handle($booking, 'Cancelado por el negocio vía comando admin');
        } catch (BookingAlreadyTerminalException) {
            $this->reply($organization, $toPhone, "La reserva #{$bookingId} ya estaba en un estado terminal.");

            return;
        }

        $this->reply($organization, $toPhone, "Listo, cancelé la reserva #{$bookingId}.");
    }

    private function confirm(Organization $organization, string $toPhone, int $bookingId): void
    {
        $booking = $organization->bookings()->find($bookingId);

        if ($booking === null) {
            $this->reply($organization, $toPhone, "No encontré la reserva #{$bookingId}.");

            return;
        }

        try {
            $this->confirmBooking->handle($booking);
        } catch (BookingAlreadyTerminalException) {
            $this->reply($organization, $toPhone, "La reserva #{$bookingId} ya estaba en un estado terminal.");

            return;
        }

        $this->reply($organization, $toPhone, "Listo, confirmé la reserva #{$bookingId}.");
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function formatBookingList(Collection $bookings): string
    {
        $bookings->loadMissing(['service', 'customer']);

        return $bookings->map(fn (Booking $booking) => sprintf(
            '#%d %s — %s (%s)',
            $booking->id,
            $booking->starts_at->format('H:i'),
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
        ))->implode("\n");
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
