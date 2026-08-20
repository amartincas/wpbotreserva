<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\RescheduleBookingCommand;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Conversations\Flows\DateFieldExtractor;
use App\Application\Conversations\Flows\FlowProgressStatus;
use App\Application\Conversations\Flows\FlowStep;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agente Gestión de Reserva (Incremento 2, Parte VIII) — cliente cancela,
 * reprograma o consulta el estado de un turno que ya tiene agendado.
 * Reutiliza CancelBookingCommand/RescheduleBookingCommand (nunca
 * BookingScheduler directo, Parte IX punto 3) y la misma infraestructura de
 * FlowStep/ConversationalFlowRunner del Hito 5 para el único campo
 * estructurado que necesita (la nueva fecha, al reprogramar).
 *
 * A diferencia de ReservaAgent, el primer mensaje no pide un dato nuevo —
 * busca las reservas activas del cliente y arranca desde ahí, porque el
 * "dato" relevante (cuál reserva) ya existe en la base, no hay que
 * preguntarlo si solo hay una.
 */
class GestionReservaAgent implements AgentInterface
{
    private const CONFIRMATION_WORDS = ['si', 'sí', 'confirmo', 'dale', 'ok', 'okay'];

    private const ACTION_CANCEL = ['cancelar', 'cancela', 'cancelalo', 'cancélalo'];

    private const ACTION_RESCHEDULE = ['reprogramar', 'reprograma', 'reprogramalo', 'cambiar fecha', 'cambiar'];

    private const ACTION_STATUS = ['estado', 'consultar', 'consultar estado', 'como va', 'cómo va'];

    /** @var FlowStep[] */
    private readonly array $rescheduleSteps;

    public function __construct(
        private readonly ConversationalFlowRunner $runner,
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly NotificationSenderInterface $notifications,
        private readonly AvailabilityCalculatorInterface $availability,
        private readonly ActiveBookingsFinderInterface $activeBookings,
        private readonly CancelBookingCommand $cancelBooking,
        private readonly RescheduleBookingCommand $rescheduleBooking,
        AiServiceInterface $ai,
    ) {
        $this->rescheduleSteps = [
            new FlowStep(
                'newDate',
                fn () => '¿Para qué día querés mover el turno?',
                new DateFieldExtractor($ai),
            ),
        ];
    }

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_booking_selection'] ?? false) === true) {
            $this->handleBookingSelection($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_action'] ?? false) === true) {
            $this->handleActionChoice($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_cancel_confirmation'] ?? false) === true) {
            $this->handleCancelConfirmation($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_new_date'] ?? false) === true) {
            $this->handleNewDateAnswer($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_slot_selection'] ?? false) === true) {
            $this->handleSlotSelection($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_reschedule_confirmation'] ?? false) === true) {
            $this->handleRescheduleConfirmation($message, $session, $organization, $draft);

            return;
        }

        // Primer mensaje del flujo (disparó Intent::GestionReserva) —
        // arranca buscando las reservas activas del cliente, nunca
        // interpreta este mensaje como respuesta a algo (mismo motivo que
        // el guard `_started` de RegistroNegocioAgent/ReservaAgent, pero
        // acá no hace falta el marcador porque no hay pregunta previa que
        // este mensaje pudiera estar contestando).
        $this->startFlow($message, $session, $organization);
    }

    private function startFlow(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $activeBookings = $this->activeBookings->forCustomer($organization, $message->fromPhone);

        if ($activeBookings->isEmpty()) {
            $this->reply($organization, $message->fromPhone, 'No tenés ninguna reserva activa en este momento.');

            return;
        }

        if ($activeBookings->count() === 1) {
            $this->presentBookingAndAskAction($session, $organization, $message->fromPhone, ['bookingId' => $activeBookings->first()->id], $activeBookings->first());

            return;
        }

        $draft = [
            '_awaiting_booking_selection' => true,
            '_candidateBookingIds' => $activeBookings->pluck('id')->all(),
        ];
        $this->drafts->put($session, $draft);
        $this->reply($organization, $message->fromPhone, $this->formatBookingOptions($activeBookings));
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
        $booking = Booking::findOrFail($bookingId);

        $this->presentBookingAndAskAction($session, $organization, $message->fromPhone, ['bookingId' => $bookingId], $booking);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function presentBookingAndAskAction(ConversationSession $session, Organization $organization, string $toPhone, array $draft, Booking $booking): void
    {
        $booking->loadMissing('service');
        $draft['_awaiting_action'] = true;
        $this->drafts->put($session, $draft);

        $this->reply($organization, $toPhone, sprintf(
            "Tenés un turno de %s el %s.\n\n¿Querés cancelarlo, reprogramarlo, o consultar el estado? (cancelar/reprogramar/estado)",
            $booking->service->name,
            $booking->starts_at->translatedFormat('l d/m/Y H:i'),
        ));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleActionChoice(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));
        $booking = Booking::findOrFail($draft['bookingId']);
        $booking->loadMissing('service');

        if (in_array($answer, self::ACTION_STATUS, true)) {
            $this->drafts->forget($session);
            $this->reply($organization, $message->fromPhone, sprintf(
                "Tu turno de %s está %s para el %s.",
                $booking->service->name,
                $this->statusLabel($booking->status),
                $booking->starts_at->translatedFormat('l d/m/Y H:i'),
            ));

            return;
        }

        if (in_array($answer, self::ACTION_CANCEL, true)) {
            $draft['_awaiting_action'] = false;
            $draft['_awaiting_cancel_confirmation'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, sprintf(
                '¿Confirmás que querés cancelar tu turno del %s? (sí/no)',
                $booking->starts_at->translatedFormat('l d/m/Y H:i'),
            ));

            return;
        }

        if (in_array($answer, self::ACTION_RESCHEDULE, true)) {
            $draft['_awaiting_action'] = false;
            $draft['_awaiting_new_date'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, ($this->rescheduleSteps[0]->prompt)($draft));

            return;
        }

        $this->reply($organization, $message->fromPhone, 'No entendí. Respondé cancelar, reprogramar o estado.');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleCancelConfirmation(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (! in_array($answer, self::CONFIRMATION_WORDS, true)) {
            $this->drafts->forget($session);
            $this->reply($organization, $message->fromPhone, 'Ok, no cancelé nada.');

            return;
        }

        $booking = Booking::findOrFail($draft['bookingId']);
        $this->cancelBooking->handle($booking, 'Cancelado por el cliente vía WhatsApp');
        $this->drafts->forget($session);

        $this->reply($organization, $message->fromPhone, 'Listo, cancelé tu turno.');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleNewDateAnswer(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->rescheduleSteps[0]->extractor->extract($message->text, $draft);
        $progress = $this->runner->advance($this->rescheduleSteps, $draft, $this->rescheduleSteps[0], $result);

        if ($progress->status === FlowProgressStatus::Invalid) {
            $this->reply($organization, $message->fromPhone, $progress->reason);

            return;
        }

        // Con un único FlowStep, Completed es el único desenlace posible
        // tras un éxito (mismo motivo documentado en ReservaAgent).
        $this->offerRescheduleSlots($session, $organization, $message->fromPhone, $progress->draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function offerRescheduleSlots(ConversationSession $session, Organization $organization, string $toPhone, array $draft): void
    {
        $booking = Booking::findOrFail($draft['bookingId']);
        $booking->loadMissing(['service', 'location', 'bookingResources.resource']);
        $resource = $booking->bookingResources->first()?->resource;

        $slots = $this->availability->availableSlots($booking->service, $booking->location, $draft['newDate'], $resource);

        if ($slots->isEmpty()) {
            unset($draft['newDate']);
            $draft['_awaiting_new_date'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $toPhone, 'No hay turnos disponibles ese día. ¿Para qué otro día te gustaría?');

            return;
        }

        $slots = $slots->values();
        $draft['_awaiting_new_date'] = false;
        $draft['_candidateSlots'] = $slots->map(fn (AvailableSlot $slot) => $slot->range->start->toIso8601String())->all();
        $draft['_awaiting_slot_selection'] = true;
        $this->drafts->put($session, $draft);

        $this->reply($organization, $toPhone, $this->formatSlotOptions($slots));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleSlotSelection(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $candidates = $draft['_candidateSlots'];

        if (! preg_match('/\d+/', $message->text, $matches) || ! isset($candidates[((int) $matches[0]) - 1])) {
            $this->reply($organization, $message->fromPhone, 'No entendí la opción. Respondé con el número del horario que preferís.');

            return;
        }

        $draft['newChosenSlot'] = $candidates[((int) $matches[0]) - 1];
        unset($draft['_awaiting_slot_selection'], $draft['_candidateSlots']);
        $draft['_awaiting_reschedule_confirmation'] = true;
        $this->drafts->put($session, $draft);

        $chosen = CarbonImmutable::parse($draft['newChosenSlot']);
        $this->reply($organization, $message->fromPhone, "¿Confirmás mover tu turno para el {$chosen->format('d/m')} a las {$chosen->format('H:i')}? (sí/no)");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleRescheduleConfirmation(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (! in_array($answer, self::CONFIRMATION_WORDS, true)) {
            $this->drafts->forget($session);
            $this->reply($organization, $message->fromPhone, 'Ok, no moví tu turno.');

            return;
        }

        $booking = Booking::findOrFail($draft['bookingId']);

        try {
            $this->rescheduleBooking->handle($booking, CarbonImmutable::parse($draft['newChosenSlot']));
        } catch (\App\Domain\Booking\Exceptions\SlotNoLongerAvailableException) {
            $this->drafts->forget($session);
            $this->reply($organization, $message->fromPhone, 'Justo se ocupó ese horario. Escribinos de nuevo si querés reprogramar.');

            return;
        }

        $this->drafts->forget($session);
        $this->reply($organization, $message->fromPhone, 'Listo, tu turno quedó reprogramado.');
    }


    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function formatBookingOptions(Collection $bookings): string
    {
        $bookings->first()->loadMissing('service');
        $options = $bookings->map(function (Booking $booking, int $i) {
            $booking->loadMissing('service');

            return ($i + 1).') '.$booking->service->name.' — '.$booking->starts_at->translatedFormat('l d/m H:i');
        })->implode("\n");

        return "Tenés varias reservas activas:\n\n{$options}\n\nRespondé con el número de la que querés gestionar.";
    }

    /**
     * @param  Collection<int, AvailableSlot>  $slots
     */
    private function formatSlotOptions(Collection $slots): string
    {
        $options = $slots->map(
            fn (AvailableSlot $slot, int $i) => ($i + 1).') '.$slot->range->start->format('d/m H:i')
        )->implode("\n");

        return "Estos son los horarios disponibles:\n\n{$options}\n\nRespondé con el número de la opción que preferís.";
    }

    private function statusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::PENDING => 'pendiente',
            BookingStatus::CONFIRMED => 'confirmado',
            BookingStatus::CANCELLED => 'cancelado',
            BookingStatus::COMPLETED => 'completado',
            BookingStatus::NO_SHOW => 'marcado como no-show',
        };
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
