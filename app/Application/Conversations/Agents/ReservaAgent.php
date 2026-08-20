<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Conversations\Flows\DateFieldExtractor;
use App\Application\Conversations\Flows\FlowProgress;
use App\Application\Conversations\Flows\FlowProgressStatus;
use App\Application\Conversations\Flows\FlowStep;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agent "normal" — implementa AgentInterface, no OrganizationlessAgentInterface,
 * porque reservar siempre ocurre dentro de una Organization ya registrada
 * (a diferencia de RegistroNegocioAgent, que es la excepción). Reutiliza
 * toda la infraestructura conversacional del Hito 5 sin cambiarla.
 *
 * Alcance MVP (Parte XII): cada Organization tiene exactamente un Location,
 * un Service y un Resource (creados así por RegisterOrganizationCommand) —
 * no hace falta preguntar cuál, solo cuándo y a nombre de quién. Ofrecer
 * horarios y confirmar son fases propias del Agent, no del Runner —
 * presentar una lista dinámica de opciones no es lo mismo que pedir un
 * campo declarativo, y forzarlo dentro de FlowStep habría sido la
 * abstracción prematura que veníamos evitando.
 */
class ReservaAgent implements AgentInterface
{
    private const CONFIRMATION_WORDS = ['si', 'sí', 'confirmo', 'dale', 'ok', 'okay'];

    /** @var FlowStep[] */
    private readonly array $steps;

    public function __construct(
        private readonly ConversationalFlowRunner $runner,
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
        private readonly AvailabilityCalculatorInterface $availability,
        private readonly CreateBookingCommand $createBooking,
        AiServiceInterface $ai,
    ) {
        $this->steps = [
            new FlowStep(
                'date',
                fn () => '¿Para qué día querés el turno?',
                new DateFieldExtractor($ai),
            ),
            new FlowStep(
                'customerName',
                fn () => '¿A nombre de quién hago la reserva?',
                new AiFieldExtractor($ai, 'nombre del cliente', 'El nombre de la persona que va a usar el turno.'),
            ),
        ];
    }

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_confirmation'] ?? false) === true) {
            $this->handleConfirmationReply($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaiting_slot_selection'] ?? false) === true) {
            $this->handleSlotSelection($message, $session, $organization, $draft);

            return;
        }

        // Primer mensaje del flujo (disparó Intent::Reserva) — todavía no es
        // una respuesta a nada, solo pregunta el primer campo (mismo motivo
        // que en RegistroNegocioAgent).
        if (($draft['_started'] ?? false) !== true) {
            $draft['_started'] = true;
            $this->drafts->put($session, $draft);
            $firstStep = $this->runner->currentStep($this->steps, $draft);
            $this->reply($organization, $message->fromPhone, ($firstStep->prompt)($draft));

            return;
        }

        $currentStep = $this->runner->currentStep($this->steps, $draft);

        if ($currentStep === null) {
            $this->offerSlots($session, $organization, $draft);

            return;
        }

        $result = $currentStep->extractor->extract($message->text, $draft);
        $progress = $this->runner->advance($this->steps, $draft, $currentStep, $result);

        match ($progress->status) {
            FlowProgressStatus::Invalid => $this->reply($organization, $message->fromPhone, $progress->reason),
            FlowProgressStatus::NextStep => $this->askNextStep($session, $organization, $message->fromPhone, $progress),
            FlowProgressStatus::Completed => $this->offerSlots($session, $organization, $progress->draft),
        };
    }

    private function askNextStep(ConversationSession $session, Organization $organization, string $toPhone, FlowProgress $progress): void
    {
        // Bug real encontrado al agregar el segundo FlowStep (nombre del
        // cliente): con un único paso, esta rama nunca se ejecutaba (advance()
        // siempre devolvía Completed), así que faltaba persistir el draft acá
        // — invisible hasta que hubo un paso intermedio de verdad que
        // atravesarla. Mismo patrón que RegistroNegocioAgent::askNextStep().
        $this->drafts->put($session, $progress->draft);
        $this->reply($organization, $toPhone, ($progress->step->prompt)($progress->draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function offerSlots(ConversationSession $session, Organization $organization, array $draft): void
    {
        $location = $organization->locations()->first();
        $service = $organization->services()->first();
        $resource = $organization->resources()->first();

        $slots = $this->availability->availableSlots($service, $location, $draft['date'], $resource);

        if ($slots->isEmpty()) {
            unset($draft['date']);
            $this->drafts->put($session, $draft);
            $this->reply($organization, $session->customer_phone->value(), 'No hay turnos disponibles ese día. ¿Para qué otro día te gustaría?');

            return;
        }

        $slots = $slots->values();
        $draft['_candidateSlots'] = $slots->map(fn (AvailableSlot $slot) => $slot->range->start->toIso8601String())->all();
        $draft['_awaiting_slot_selection'] = true;
        $this->drafts->put($session, $draft);

        $this->reply($organization, $session->customer_phone->value(), $this->formatSlotOptions($slots));
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

        $draft['chosenSlot'] = $candidates[((int) $matches[0]) - 1];
        unset($draft['_awaiting_slot_selection'], $draft['_candidateSlots']);
        $draft['_awaiting_confirmation'] = true;
        $this->drafts->put($session, $draft);

        $chosen = CarbonImmutable::parse($draft['chosenSlot']);
        $this->reply($organization, $message->fromPhone, "Confirmás el turno para el {$chosen->format('d/m')} a las {$chosen->format('H:i')}? (sí/no)");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleConfirmationReply(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (! in_array($answer, self::CONFIRMATION_WORDS, true)) {
            $this->reply($organization, $message->fromPhone, 'Decime "sí" para confirmar el turno.');

            return;
        }

        try {
            $result = $this->createBooking->handle(new CreateBookingData(
                organization: $organization,
                location: $organization->locations()->first(),
                service: $organization->services()->first(),
                customerPhone: $message->fromPhone,
                customerName: $draft['customerName'] ?? null,
                startsAt: CarbonImmutable::parse($draft['chosenSlot']),
                resource: $organization->resources()->first(),
            ));
        } catch (SlotNoLongerAvailableException) {
            // Alguien más ganó la carrera por ese horario entre que se
            // ofreció y se confirmó (BookingScheduler, Hito 2) — se le pide
            // al cliente elegir de nuevo, no se asume que sigue disponible.
            $this->drafts->forget($session);
            $this->reply($organization, $message->fromPhone, 'Justo se ocupó ese horario. ¿Para qué día querés el turno?');

            return;
        }

        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->reply(
            $organization,
            $message->fromPhone,
            "¡Listo! Tu turno de {$result->serviceName} quedó confirmado para el {$result->startsAt->format('d/m')} a las {$result->startsAt->format('H:i')}."
        );
    }

    /**
     * @param  Collection<int, AvailableSlot>  $slots
     */
    private function formatSlotOptions($slots): string
    {
        $options = $slots->map(
            fn (AvailableSlot $slot, int $i) => ($i + 1).') '.$slot->range->start->format('d/m H:i')
        )->implode("\n");

        return "Estos son los horarios disponibles:\n\n{$options}\n\nRespondé con el número de la opción que preferís.";
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
