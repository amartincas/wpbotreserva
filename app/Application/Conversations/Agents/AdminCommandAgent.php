<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\ConfirmBookingCommand;
use App\Application\Booking\MarkBookingNoShowCommand;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Conversations\ConversationReplier;
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
 * que no hay estado que mantener entre turnos. Por eso mismo limpia
 * current_intent al terminar (B3): si quedara activo, cualquier texto libre
 * posterior del owner volvería acá por continuidad y recibiría "Comando no
 * reconocido." durante todo el TTL. Responde por el Channel de la sesión
 * (ConversationReplier), no por el de la Organization.
 *
 * `$organization->bookings()->find($id)` (no Booking::find()) es lo que
 * hace que "cancelar <id>" nunca pueda tocar la reserva de OTRO negocio —
 * un ID que no pertenece a esta Organization simplemente no aparece, ni
 * siquiera como error distinto de "no existe".
 */
class AdminCommandAgent implements AgentInterface
{
    public function __construct(
        private readonly ConversationReplier $replier,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly CancelBookingCommand $cancelBooking,
        private readonly ConfirmBookingCommand $confirmBooking,
        private readonly MarkBookingNoShowCommand $markNoShowBooking,
        private readonly AgendaQueryService $agenda,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        try {
            $this->execute($message, $session, $organization);
        } finally {
            $this->sessions->recordIntent($session, null);
        }
    }

    private function execute(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $text = trim($message->text);

        if (preg_match('/^reservas\s+hoy$/iu', $text)) {
            $this->listForDate($session, $organization, $message->fromPhone, CarbonImmutable::now($organization->timezone), 'hoy');

            return;
        }

        if (preg_match('/^reservas\s+(\d{1,2})\/(\d{1,2})\/(\d{4})$/iu', $text, $matches)) {
            $this->listForRequestedDate($session, $organization, $message->fromPhone, (int) $matches[1], (int) $matches[2], (int) $matches[3]);

            return;
        }

        if (preg_match('/^cancelar\s+(\d+)$/iu', $text, $matches)) {
            $this->cancel($session, $organization, $message->fromPhone, (int) $matches[1]);

            return;
        }

        if (preg_match('/^confirmar\s+(\d+)$/iu', $text, $matches)) {
            $this->confirm($session, $organization, $message->fromPhone, (int) $matches[1]);

            return;
        }

        if (preg_match('/^ausente\s+(\d+)$/iu', $text, $matches)) {
            $this->markNoShow($session, $organization, $message->fromPhone, (int) $matches[1]);

            return;
        }

        // No debería ocurrir: DeterministicAdminCommandStrategy ya validó el
        // mismo patrón antes de clasificar Intent::AdminCommand. Devuelve un
        // mensaje en vez de romper si algún día ese contrato se desalinea.
        $this->reply($session, $message->fromPhone, 'Comando no reconocido.');
    }

    /**
     * checkdate() (no Carbon::createFromFormat) a propósito: Carbon es
     * permisivo con fechas fuera de rango (ej. 31/02 rueda a marzo) —
     * checkdate() es la validación de calendario real de PHP, exactamente
     * lo que hace falta antes de aceptar una fecha que un dueño tipeó a mano.
     */
    private function listForRequestedDate(ConversationSession $session, Organization $organization, string $toPhone, int $day, int $month, int $year): void
    {
        if (! checkdate($month, $day, $year)) {
            $this->reply($session, $toPhone, 'Esa fecha no es válida. Usá el formato dd/mm/aaaa.');

            return;
        }

        $date = CarbonImmutable::create($year, $month, $day, timezone: $organization->timezone);

        $this->listForDate($session, $organization, $toPhone, $date, $date->format('d/m/Y'));
    }

    /**
     * Query delegada a AgendaQueryService (Fase 4) — mismo comportamiento
     * observable de siempre (excluye CANCELLED, sin filtro de Resource),
     * ahora compartido con AgendaProfesionalAgent en vez de duplicado.
     */
    private function listForDate(ConversationSession $session, Organization $organization, string $toPhone, CarbonImmutable $date, string $label): void
    {
        $bookings = $this->agenda->forDate($organization, $date);

        if ($bookings->isEmpty()) {
            $this->reply($session, $toPhone, "No tenés reservas para {$label}.");

            return;
        }

        $this->reply($session, $toPhone, "Reservas de {$label}:\n\n".$this->formatBookingList($bookings, $organization));
    }

    private function cancel(ConversationSession $session, Organization $organization, string $toPhone, int $bookingId): void
    {
        $booking = $organization->bookings()->find($bookingId);

        if ($booking === null) {
            $this->reply($session, $toPhone, "No encontré la reserva #{$bookingId}.");

            return;
        }

        try {
            $this->cancelBooking->handle($booking, 'Cancelado por el negocio vía comando admin');
        } catch (BookingAlreadyTerminalException) {
            $this->reply($session, $toPhone, "La reserva #{$bookingId} ya estaba en un estado terminal.");

            return;
        }

        $this->reply($session, $toPhone, "Listo, cancelé la reserva #{$bookingId}.");
    }

    private function confirm(ConversationSession $session, Organization $organization, string $toPhone, int $bookingId): void
    {
        $booking = $organization->bookings()->find($bookingId);

        if ($booking === null) {
            $this->reply($session, $toPhone, "No encontré la reserva #{$bookingId}.");

            return;
        }

        try {
            $this->confirmBooking->handle($booking);
        } catch (BookingAlreadyTerminalException) {
            $this->reply($session, $toPhone, "La reserva #{$bookingId} ya estaba en un estado terminal.");

            return;
        }

        $this->reply($session, $toPhone, "Listo, confirmé la reserva #{$bookingId}.");
    }

    /**
     * Único de los 3 comandos de mutación (junto con cancel/confirm) cuyo
     * try/catch importa de verdad más allá de "no romper": si la reserva
     * está CANCELLED, BookingAlreadyTerminalException es correcto (no tiene
     * sentido marcar ausente algo que se canceló) — pero si está COMPLETED
     * (el caso real que motivó este comando: revertir un auto-completado
     * del respaldo de 7 días), markNoShow() la acepta sin problema.
     */
    private function markNoShow(ConversationSession $session, Organization $organization, string $toPhone, int $bookingId): void
    {
        $booking = $organization->bookings()->find($bookingId);

        if ($booking === null) {
            $this->reply($session, $toPhone, "No encontré la reserva #{$bookingId}.");

            return;
        }

        try {
            $this->markNoShowBooking->handle($booking);
        } catch (BookingAlreadyTerminalException) {
            $this->reply($session, $toPhone, "La reserva #{$bookingId} ya estaba en un estado terminal.");

            return;
        }

        $this->reply($session, $toPhone, "Listo, marqué la reserva #{$bookingId} como ausente.");
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function formatBookingList(Collection $bookings, Organization $organization): string
    {
        $bookings->loadMissing(['service', 'customer']);

        // setTimezone($organization->timezone) — corrección transversal de
        // timezone: starts_at, releído desde la base, viene en
        // config('app.timezone'), nunca en el timezone de la Organization;
        // sin esto, la hora mostrada al dueño sería la del servidor.
        return $bookings->map(fn (Booking $booking) => sprintf(
            '#%d %s — %s (%s)',
            $booking->id,
            $booking->starts_at->setTimezone($organization->timezone)->format('H:i'),
            $booking->service->name,
            $booking->customer->name ?? $booking->customer->phone->value(),
        ))->implode("\n");
    }

    private function reply(ConversationSession $session, string $toPhone, string $text): void
    {
        $this->replier->send($session, $toPhone, $text);
    }
}
