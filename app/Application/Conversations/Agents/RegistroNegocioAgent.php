<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Conversations\Flows\FlowProgress;
use App\Application\Conversations\Flows\FlowProgressStatus;
use App\Application\Conversations\Flows\FlowStep;
use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Único Agent capaz de operar sin Organization resuelta (implementa
 * OrganizationlessAgentInterface, no AgentInterface) — es exactamente el
 * que la crea. FlowStep[] construido una única vez en el constructor
 * (Hito 5, regla validada antes de implementar) — nunca varía según el
 * draft, la sesión, ni ningún dato de negocio en runtime.
 *
 * ownerPhone y channel nunca se preguntan como FlowStep: ya se conocen del
 * propio mensaje/sesión — preguntarlos sería redundante y rompería "un dato
 * a la vez, solo lo que hace falta".
 *
 * Responde vía ChannelClientInterface directo (no NotificationSenderInterface,
 * que resuelve el Channel A TRAVÉS de una Organization que acá todavía no
 * existe) — consecuencia directa de que Channel y Organization son
 * conceptos desacoplados.
 */
class RegistroNegocioAgent implements OrganizationlessAgentInterface
{
    private const CONFIRMATION_WORDS = ['si', 'sí', 'confirmo', 'dale', 'ok', 'okay'];

    /** @var FlowStep[] */
    private readonly array $steps;

    public function __construct(
        private readonly ConversationalFlowRunner $runner,
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly ChannelClientInterface $channelClient,
        private readonly RegisterOrganizationCommand $registerOrganization,
        AiServiceInterface $ai,
    ) {
        $this->steps = [
            new FlowStep(
                'organizationName',
                fn () => '¿Cuál es el nombre de tu negocio?',
                new AiFieldExtractor($ai, 'nombre del negocio', 'El nombre comercial con el que opera el negocio.'),
            ),
            new FlowStep(
                'city',
                fn () => '¿En qué ciudad está?',
                new AiFieldExtractor($ai, 'ciudad', 'La ciudad donde opera el negocio.'),
            ),
            new FlowStep(
                'address',
                fn () => '¿Cuál es la dirección?',
                new AiFieldExtractor($ai, 'dirección', 'La dirección física del negocio.'),
            ),
            new FlowStep(
                'serviceName',
                fn () => '¿Qué servicio ofrecés? (por ahora, contame uno solo)',
                new AiFieldExtractor($ai, 'nombre del servicio', 'El servicio principal que ofrece el negocio.'),
            ),
            new FlowStep(
                'serviceDurationMinutes',
                fn () => '¿Cuánto dura ese servicio, en minutos?',
                new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.'),
            ),
            new FlowStep(
                'resourceName',
                fn () => '¿Cómo se llama quién va a atender? (por ahora, una sola persona o recurso)',
                new AiFieldExtractor($ai, 'nombre del recurso', 'El nombre de la persona o recurso que presta el servicio.'),
            ),
            new FlowStep(
                'weeklySchedule',
                fn () => '¿Qué días y en qué horario atendés? (ej: "Lunes a Viernes de 9 a 17")',
                new WeeklyScheduleFieldExtractor($ai),
            ),
        ];
    }

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_confirmation'] ?? false) === true) {
            $this->handleConfirmationReply($message, $session, $draft);

            return;
        }

        // Primer mensaje del flujo (disparó Intent::RegistroNegocio) — no es
        // una respuesta a nada todavía, solo pregunta el primer campo. Sin
        // este marcador, el mensaje que activó el flujo se interpretaría
        // como respuesta a una pregunta que nunca se hizo.
        if (($draft['_started'] ?? false) !== true) {
            $draft['_started'] = true;
            $this->drafts->put($session, $draft);
            $firstStep = $this->runner->currentStep($this->steps, $draft);
            $this->reply($session, ($firstStep->prompt)($draft));

            return;
        }

        $currentStep = $this->runner->currentStep($this->steps, $draft);

        // No debería ocurrir (si todos los steps ya están respondidos, ya se
        // habría pasado a confirmación) — devuelve al camino de confirmación
        // en vez de romper.
        if ($currentStep === null) {
            $this->beginConfirmation($session, $draft);

            return;
        }

        $result = $currentStep->extractor->extract($message->text, $draft);
        $progress = $this->runner->advance($this->steps, $draft, $currentStep, $result);

        match ($progress->status) {
            FlowProgressStatus::Invalid => $this->reply($session, $progress->reason),
            FlowProgressStatus::NextStep => $this->askNextStep($session, $progress),
            FlowProgressStatus::Completed => $this->beginConfirmation($session, $progress->draft),
        };
    }

    private function askNextStep(ConversationSession $session, FlowProgress $progress): void
    {
        $this->drafts->put($session, $progress->draft);
        $this->reply($session, ($progress->step->prompt)($progress->draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginConfirmation(ConversationSession $session, array $draft): void
    {
        $draft['_awaiting_confirmation'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($session, $this->buildSummary($draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleConfirmationReply(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (! in_array($answer, self::CONFIRMATION_WORDS, true)) {
            $this->reply($session, 'Decime "sí" para confirmar y crear tu negocio con estos datos.');

            return;
        }

        $result = $this->registerOrganization->handle(new RegisterOrganizationData(
            organizationName: $draft['organizationName'],
            ownerPhone: $message->fromPhone,
            channel: $session->channel,
            city: $draft['city'] ?? null,
            address: $draft['address'] ?? null,
            serviceName: $draft['serviceName'],
            serviceDurationMinutes: (int) $draft['serviceDurationMinutes'],
            resourceName: $draft['resourceName'],
            weeklySchedule: $draft['weeklySchedule'],
        ));

        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->reply($session, "¡Listo! «{$result->organizationName}» quedó registrado. Ya podés recibir reservas por acá.");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function buildSummary(array $draft): string
    {
        /** @var WeeklyScheduleSlot[] $schedule */
        $schedule = $draft['weeklySchedule'];
        $scheduleText = implode(', ', array_map(
            fn (WeeklyScheduleSlot $slot) => "día {$slot->weekday} de {$slot->startTime} a {$slot->endTime}",
            $schedule
        ));

        return <<<TEXT
            Confirmá que estos datos son correctos:

            Negocio: {$draft['organizationName']}
            Ciudad: {$draft['city']}
            Dirección: {$draft['address']}
            Servicio: {$draft['serviceName']} ({$draft['serviceDurationMinutes']} min)
            Atiende: {$draft['resourceName']}
            Horario: {$scheduleText}

            ¿Confirmás? (sí/no)
            TEXT;
    }

    private function reply(ConversationSession $session, string $text): void
    {
        $this->channelClient->sendTextMessage($session->channel, $session->customer_phone->value(), $text);
    }
}
