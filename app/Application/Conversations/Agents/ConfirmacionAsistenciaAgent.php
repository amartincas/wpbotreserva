<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\RecordBookingAttendanceCommand;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\CRM\Customer;
use App\Domain\Tenancy\Organization;
use App\Enums\AttendanceStatus;
use Illuminate\Support\Collection;

/**
 * Agente Confirmación de Asistencia (Fase 3) — responde a un recordatorio
 * ya enviado (Intent::ConfirmacionAsistencia, producido únicamente por
 * PendingAttendanceConfirmationStrategy). Nunca lo dispara el cliente por
 * iniciativa propia, siempre es respuesta a algo que el sistema ya
 * preguntó.
 *
 * "No" no registra DECLINED acá — solo marca la fila pendiente y entrega el
 * control a GestionReservaAgent (reutilizado tal cual, sin duplicar su
 * lógica de Cancelar/Modificar). DECLINED solo se persiste si el cliente
 * efectivamente cancela (ver RecordAttendanceDeclineOnBookingCancelled) —
 * evita registrar como "no va a venir" a alguien que solo quiere
 * reprogramar (Diseño Fase 3, sección 1, corrección aprobada).
 */
class ConfirmacionAsistenciaAgent implements AgentInterface
{
    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
        private readonly RecordBookingAttendanceCommand $recordAttendance,
        private readonly GestionReservaAgent $gestionReservaAgent,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaitingAttendanceBookingSelection'] ?? false) === true) {
            $this->handleBookingSelection($message, $session, $organization, $draft);

            return;
        }

        $this->startFlow($message, $session, $organization);
    }

    private function startFlow(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $answer = $this->normalizeAnswer($message->text);
        $pending = $this->pendingConfirmationsFor($organization, $message->fromPhone);

        // Defensivo: PendingAttendanceConfirmationStrategy ya verificó que
        // existía al menos 1 fila antes de despachar este Intent, pero
        // entre ese chequeo y este handle() la fila pudo resolverse por
        // otro camino (ej. respuesta duplicada procesada en paralelo) — no
        // se asume que sigue existiendo.
        if ($pending->isEmpty()) {
            $this->reply($organization, $message->fromPhone, 'No tengo ningún recordatorio pendiente de respuesta en este momento.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($pending->count() > 1) {
            $draft = [
                '_awaitingAttendanceBookingSelection' => true,
                '_pendingAnswer' => $answer,
                '_candidateBookingIds' => $pending->pluck('booking_id')->all(),
            ];
            $this->sessions->recordIntent($session, Intent::ConfirmacionAsistencia);
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, $this->formatPendingOptions($pending, $organization));

            return;
        }

        $this->resolveAnswer($session, $organization, $message->fromPhone, $pending->first(), $answer);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleBookingSelection(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $candidates = $draft['_candidateBookingIds'];

        if (! preg_match('/\d+/', $message->text, $matches) || ! isset($candidates[((int) $matches[0]) - 1])) {
            $this->reply($organization, $message->fromPhone, 'No entendí la opción. Respondé con el número de la reserva.');

            return;
        }

        $bookingId = $candidates[((int) $matches[0]) - 1];
        $answer = $draft['_pendingAnswer'];
        $pendingRow = PendingAttendanceConfirmation::where('booking_id', $bookingId)->first();

        $this->drafts->forget($session);

        if ($pendingRow === null) {
            $this->reply($organization, $message->fromPhone, 'Esa reserva ya no está disponible para responder.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        $this->resolveAnswer($session, $organization, $message->fromPhone, $pendingRow, $answer);
    }

    private function resolveAnswer(ConversationSession $session, Organization $organization, string $toPhone, PendingAttendanceConfirmation $pending, ?string $answer): void
    {
        $booking = $pending->booking ?? Booking::find($pending->booking_id);

        // Multi-tenancy: defensa en profundidad, no confiar únicamente en
        // que la consulta previa ya haya scopeado por Organization
        // (mismo criterio que ProfessionalRecipientResolver, Fase 2B).
        if ($booking === null || $booking->organization_id !== $organization->id) {
            $this->reply($organization, $toPhone, 'No pude encontrar esa reserva.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($booking->attendance_status !== null) {
            $this->reply($organization, $toPhone, $this->alreadyRespondedMessage($booking));
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($booking->isTerminal()) {
            $pending->delete();
            $this->reply($organization, $toPhone, 'Esa reserva ya no está activa.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($booking->starts_at->isPast()) {
            $pending->delete();
            $this->reply($organization, $toPhone, 'Esa reserva ya pasó.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($answer === 'si') {
            $this->recordAttendance->handle($booking, AttendanceStatus::CONFIRMED);
            $pending->delete();
            $this->reply($organization, $toPhone, '¡Genial! Te esperamos.');
            $this->sessions->recordIntent($session, null);

            return;
        }

        if ($answer === 'no') {
            $pending->update(['declined_at' => now()]);
            $this->sessions->recordIntent($session, Intent::GestionReserva);
            $this->gestionReservaAgent->presentBookingAndAskAction($session, $organization, $toPhone, ['bookingId' => $booking->id], $booking);

            return;
        }

        // Defensivo: no debería alcanzarse (la strategy solo despacha con
        // texto ya reconocido), pero _pendingAnswer pudo corromperse entre
        // el primer y el segundo turno de la desambiguación.
        $this->reply($organization, $toPhone, 'No entendí tu respuesta. ¿Podés escribir "sí" o "no" de nuevo?');
        $this->sessions->recordIntent($session, null);
    }

    /**
     * @return Collection<int, PendingAttendanceConfirmation>
     */
    private function pendingConfirmationsFor(Organization $organization, string $phone): Collection
    {
        $customer = Customer::where('organization_id', $organization->id)->where('phone', $phone)->first();

        if ($customer === null) {
            return collect();
        }

        return PendingAttendanceConfirmation::whereIn('booking_id', $customer->bookings()->pluck('id'))
            ->where('expires_at', '>', now())
            ->with('booking.service')
            ->get();
    }

    private function normalizeAnswer(string $text): ?string
    {
        return match (mb_strtolower(trim($text))) {
            'si', 'sí', 'confirmar_asistencia' => 'si',
            'no', 'no_asistencia_reserva' => 'no',
            default => null,
        };
    }

    private function alreadyRespondedMessage(Booking $booking): string
    {
        return $booking->attendance_status === AttendanceStatus::CONFIRMED
            ? 'Ya habías confirmado tu asistencia a esa reserva.'
            : 'Ya habías respondido sobre esa reserva.';
    }

    /**
     * @param  Collection<int, PendingAttendanceConfirmation>  $pending
     */
    private function formatPendingOptions(Collection $pending, Organization $organization): string
    {
        $options = $pending->values()->map(
            fn (PendingAttendanceConfirmation $p, int $i) => ($i + 1).') '.$p->booking->service->name.' — '.$p->booking->starts_at->setTimezone($organization->timezone)->translatedFormat('l d/m H:i')
        )->implode("\n");

        return "Tenés más de un recordatorio pendiente:\n\n{$options}\n\nRespondé con el número de la reserva a la que te referís.";
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
