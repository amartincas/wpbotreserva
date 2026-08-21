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
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

/**
 * Único Agent capaz de operar sin Organization resuelta (implementa
 * OrganizationlessAgentInterface, no AgentInterface) — es exactamente el
 * que la crea. FlowStep[] construido una única vez en el constructor
 * (Hito 5) — cubre solo los 3 campos de valor único (nombre/ciudad/
 * dirección); servicios y recursos (Incremento 4) son de cantidad
 * variable, así que se recolectan con un mini estado propio en el draft
 * en vez de forzarlos dentro de FlowStep/ConversationalFlowRunner, que
 * fueron validados deliberadamente para "N campos fijos", no bucles.
 *
 * ownerPhone y channel nunca se preguntan como FlowStep: ya se conocen del
 * propio mensaje/sesión — preguntarlos sería redundante y rompería "un dato
 * a la vez, solo lo que hace falta".
 *
 * Todo recurso queda habilitado para todo servicio (ver nota en
 * RegisterOrganizationData) — no se pregunta la asignación fina por
 * conversación todavía.
 *
 * Responde vía ChannelClientInterface directo (no NotificationSenderInterface,
 * que resuelve el Channel A TRAVÉS de una Organization que acá todavía no
 * existe) — consecuencia directa de que Channel y Organization son
 * conceptos desacoplados.
 */
class RegistroNegocioAgent implements OrganizationlessAgentInterface
{
    private const YES_WORDS = ['si', 'sí', 'confirmo', 'dale', 'ok', 'okay'];

    private const NO_WORDS = ['no', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna'];

    /** @var FlowStep[] */
    private readonly array $steps;

    private readonly AiFieldExtractor $serviceNameExtractor;

    private readonly AiFieldExtractor $serviceDurationExtractor;

    private readonly AiFieldExtractor $resourceNameExtractor;

    private readonly WeeklyScheduleFieldExtractor $weeklyScheduleExtractor;

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
        ];

        $this->serviceNameExtractor = new AiFieldExtractor($ai, 'nombre del servicio', 'Un servicio que ofrece el negocio.');
        $this->serviceDurationExtractor = new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.');
        $this->resourceNameExtractor = new AiFieldExtractor($ai, 'nombre del recurso', 'El nombre de la persona o recurso que va a atender.');
        $this->weeklyScheduleExtractor = new WeeklyScheduleFieldExtractor($ai);
    }

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_confirmation'] ?? false) === true) {
            $this->handleConfirmationReply($message, $session, $draft);

            return;
        }

        if (($draft['_awaitingAddAnotherResource'] ?? false) === true) {
            $this->handleAddAnotherResource($message, $session, $draft);

            return;
        }

        if (($draft['_collectingResources'] ?? false) === true) {
            $this->handleResourceStep($message, $session, $draft);

            return;
        }

        if (($draft['_awaitingAddAnotherService'] ?? false) === true) {
            $this->handleAddAnotherService($message, $session, $draft);

            return;
        }

        if (($draft['_collectingServices'] ?? false) === true) {
            $this->handleServiceStep($message, $session, $draft);

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

        // No debería ocurrir (si los 3 campos fijos ya están respondidos, ya
        // se habría pasado a la fase de servicios) — devuelve a esa fase en
        // vez de romper.
        if ($currentStep === null) {
            $this->beginServicesPhase($session, $draft);

            return;
        }

        $result = $currentStep->extractor->extract($message->text, $draft);
        $progress = $this->runner->advance($this->steps, $draft, $currentStep, $result);

        match ($progress->status) {
            FlowProgressStatus::Invalid => $this->reply($session, $progress->reason),
            FlowProgressStatus::NextStep => $this->askNextStep($session, $progress),
            FlowProgressStatus::Completed => $this->beginServicesPhase($session, $progress->draft),
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
    private function beginServicesPhase(ConversationSession $session, array $draft): void
    {
        $draft['_collectingServices'] = true;
        $draft['services'] = [];
        $this->drafts->put($session, $draft);
        $this->reply($session, '¿Qué servicio ofrecés? (contame uno a la vez)');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceStep(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        if (! isset($draft['_currentServiceName'])) {
            $result = $this->serviceNameExtractor->extract($message->text, $draft);

            if (! $result->successful) {
                $this->reply($session, $result->reason);

                return;
            }

            $draft['_currentServiceName'] = $result->value;
            $this->drafts->put($session, $draft);
            $this->reply($session, "¿Cuánto dura {$result->value}, en minutos?");

            return;
        }

        $result = $this->serviceDurationExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($session, $result->reason);

            return;
        }

        $draft['services'][] = ['name' => $draft['_currentServiceName'], 'durationMinutes' => (int) $result->value];
        unset($draft['_currentServiceName']);
        $draft['_awaitingAddAnotherService'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($session, '¿Agregás otro servicio? (sí/no)');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleAddAnotherService(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            unset($draft['_awaitingAddAnotherService']);
            $this->drafts->put($session, $draft);
            $this->reply($session, '¿Cuál es el nombre del servicio?');

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            unset($draft['_awaitingAddAnotherService'], $draft['_collectingServices']);
            $this->beginResourcesPhase($session, $draft);

            return;
        }

        $this->reply($session, 'Decime "sí" o "no": ¿agregás otro servicio?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginResourcesPhase(ConversationSession $session, array $draft): void
    {
        $draft['_collectingResources'] = true;
        $draft['resources'] = [];
        $this->drafts->put($session, $draft);
        $this->reply($session, '¿Cómo se llama la primera persona o recurso que va a atender?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleResourceStep(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        if (! isset($draft['_currentResourceName'])) {
            $result = $this->resourceNameExtractor->extract($message->text, $draft);

            if (! $result->successful) {
                $this->reply($session, $result->reason);

                return;
            }

            $draft['_currentResourceName'] = $result->value;
            $this->drafts->put($session, $draft);
            $this->reply($session, "¿Qué días y en qué horario atiende {$result->value}? (ej: \"Lunes a Viernes de 9 a 17\")");

            return;
        }

        $result = $this->weeklyScheduleExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($session, $result->reason);

            return;
        }

        $draft['resources'][] = ['name' => $draft['_currentResourceName'], 'weeklySchedule' => $result->value];
        unset($draft['_currentResourceName']);
        $draft['_awaitingAddAnotherResource'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($session, '¿Agregás otro recurso? (sí/no)');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleAddAnotherResource(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            unset($draft['_awaitingAddAnotherResource']);
            $this->drafts->put($session, $draft);
            $this->reply($session, '¿Cómo se llama la persona o recurso?');

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            unset($draft['_awaitingAddAnotherResource'], $draft['_collectingResources']);
            $this->beginConfirmation($session, $draft);

            return;
        }

        $this->reply($session, 'Decime "sí" o "no": ¿agregás otro recurso?');
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

        if (! in_array($answer, self::YES_WORDS, true)) {
            $this->reply($session, 'Decime "sí" para confirmar y crear tu negocio con estos datos.');

            return;
        }

        $services = array_map(
            fn (array $s) => new ServiceRegistrationData($s['name'], $s['durationMinutes']),
            $draft['services'],
        );
        $resources = array_map(
            fn (array $r) => new ResourceRegistrationData($r['name'], $r['weeklySchedule']),
            $draft['resources'],
        );

        $result = $this->registerOrganization->handle(new RegisterOrganizationData(
            organizationName: $draft['organizationName'],
            ownerPhone: $message->fromPhone,
            channel: $session->channel,
            city: $draft['city'] ?? null,
            address: $draft['address'] ?? null,
            services: $services,
            resources: $resources,
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
        $servicesText = implode("\n", array_map(
            fn (array $s) => "- {$s['name']} ({$s['durationMinutes']} min)",
            $draft['services'],
        ));

        $resourcesText = implode("\n", array_map(
            function (array $r) {
                /** @var WeeklyScheduleSlot[] $schedule */
                $schedule = $r['weeklySchedule'];
                $scheduleText = implode(', ', array_map(
                    fn (WeeklyScheduleSlot $slot) => "día {$slot->weekday} de {$slot->startTime} a {$slot->endTime}",
                    $schedule
                ));

                return "- {$r['name']}: {$scheduleText}";
            },
            $draft['resources'],
        ));

        return <<<TEXT
            Confirmá que estos datos son correctos:

            Negocio: {$draft['organizationName']}
            Ciudad: {$draft['city']}
            Dirección: {$draft['address']}

            Servicios:
            {$servicesText}

            Atienden:
            {$resourcesText}

            ¿Confirmás? (sí/no)
            TEXT;
    }

    private function reply(ConversationSession $session, string $text): void
    {
        $this->channelClient->sendTextMessage($session->channel, $session->customer_phone->value(), $text);
    }
}
