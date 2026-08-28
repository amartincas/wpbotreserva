<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Conversations\Flows\DraftResourceCatalog;
use App\Application\Conversations\Flows\FlowProgress;
use App\Application\Conversations\Flows\FlowProgressStatus;
use App\Application\Conversations\Flows\FlowStep;
use App\Application\Conversations\Flows\ServiceResourceSelectionFlow;
use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

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
 * Los recursos se recolectan ANIDADOS dentro de cada servicio (¿quién lo
 * presta?), vía ServiceResourceSelectionFlow con un DraftResourceCatalog —
 * la misma sub-conversación que ya usaba GestionNegocioAgent para agregar un
 * servicio a un negocio existente, ahora compartida. La diferencia real es
 * que acá todavía no existe una Organization: nada se persiste hasta la
 * confirmación final (RegisterOrganizationCommand, una única transacción),
 * así que el catálogo de recursos vive en el draft, no en BD.
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

    // Los ids "si"/"no" ya son valores válidos de YES_WORDS/NO_WORDS de
    // arriba — un click de botón entra por el mismo camino que si el
    // cliente hubiera tipeado la palabra, sin que este Agent necesite
    // saber que existió un botón.
    private const YES_NO_BUTTONS = [
        ['id' => 'si', 'title' => 'Sí'],
        ['id' => 'no', 'title' => 'No'],
    ];

    /** @var FlowStep[] */
    private readonly array $steps;

    private readonly AiFieldExtractor $serviceNameExtractor;

    private readonly AiFieldExtractor $serviceDurationExtractor;

    private readonly ServiceResourceSelectionFlow $resourceFlow;

    public function __construct(
        private readonly ConversationalFlowRunner $runner,
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly ChannelClientInterface $channelClient,
        private readonly RegisterOrganizationCommand $registerOrganization,
        private readonly BotMessageRepository $botMessages,
        AiServiceInterface $ai,
    ) {
        $this->steps = [
            new FlowStep(
                'organizationName',
                fn () => $this->botMessages->render('registro.nombre_negocio') ?? '¿Cuál es el nombre de tu negocio?',
                new AiFieldExtractor($ai, 'nombre del negocio', 'El nombre comercial con el que opera el negocio.', $botMessages),
            ),
            new FlowStep(
                'city',
                fn () => $this->botMessages->render('registro.ciudad') ?? '¿En qué ciudad está?',
                new AiFieldExtractor($ai, 'ciudad', 'La ciudad donde opera el negocio.', $botMessages),
            ),
            new FlowStep(
                'address',
                fn () => $this->botMessages->render('registro.direccion') ?? '¿Cuál es la dirección?',
                new AiFieldExtractor($ai, 'dirección', 'La dirección física del negocio.', $botMessages),
            ),
        ];

        $this->serviceNameExtractor = new AiFieldExtractor($ai, 'nombre del servicio', 'Un servicio que ofrece el negocio.', $botMessages);
        $this->serviceDurationExtractor = new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.', $botMessages);

        // DraftResourceCatalog no tiene dependencias (nunca toca BD), así
        // que el Flow se puede armar una única vez acá — a diferencia de
        // GestionNegocioAgent, que necesita una Organization por mensaje.
        $this->resourceFlow = new ServiceResourceSelectionFlow(
            new DraftResourceCatalog,
            new AiFieldExtractor($ai, 'nombre del recurso', 'El nombre de la persona o recurso que va a atender.', $botMessages),
            new WeeklyScheduleFieldExtractor($ai, $botMessages),
            self::YES_WORDS,
            self::NO_WORDS,
            $botMessages,
        );
    }

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_confirmation'] ?? false) === true) {
            $this->handleConfirmationReply($message, $session, $draft);

            return;
        }

        if (($draft['_awaitingNameConfirmation'] ?? false) === true) {
            $this->handleNameConfirmation($message, $session, $draft);

            return;
        }

        if ($this->resourceFlow->isAwaitingInput($draft)) {
            $draft = $this->resourceFlow->handle(
                $message,
                $draft,
                fn (string $text) => $this->reply($session, $text),
                fn (string $text) => $this->replyYesNo($session, $text),
                fn (array $draft) => $this->finishServiceResourceSelection($session, $draft),
            );
            $this->drafts->put($session, $draft);

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
            // Burbuja aparte del saludo, no concatenada a la primera
            // pregunta — se siente como un saludo real seguido de la
            // pregunta, no un párrafo largo.
            $this->reply($session, $this->botMessages->render('saludo.primer_mensaje') ?? '¡Hola! Soy el asistente de WpbotReserva.');
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

        // Caso real (Incremento 4, y de nuevo en una segunda ronda de
        // pruebas): un nombre de una sola palabra ("Impulzar") a veces lo
        // rechazaba el extractor de IA de plano (NO_ENCONTRADO) por no
        // "sonar" a nombre de negocio, y otras veces lo aceptaba más rápido
        // de lo esperado — en vez de confiar ciegamente en cualquiera de los
        // dos resultados, se confirma con la persona antes de avanzar.
        // Cuando el extractor directamente falla en esta pregunta puntual
        // ("¿cómo se llama tu negocio?"), no tiene sentido volver a
        // preguntar lo mismo con un "no entendí" — la respuesta cruda YA es
        // el candidato más probable, así que se usa tal cual y se confirma
        // (el mismo mecanismo de abajo ya cubre el caso de que esté mal).
        // Nombres de varias palabras que SÍ extrajo la IA con éxito no piden
        // esta confirmación — no agregar fricción donde no hubo ambigüedad
        // real.
        if ($currentStep->key === 'organizationName' && ! $result->successful) {
            $this->askNameConfirmation($session, $draft, trim($message->text));

            return;
        }

        if ($currentStep->key === 'organizationName' && $result->successful && $this->isSingleWordName($result->value)) {
            $this->askNameConfirmation($session, $draft, $result->value);

            return;
        }

        $progress = $this->runner->advance($this->steps, $draft, $currentStep, $result);

        match ($progress->status) {
            FlowProgressStatus::Invalid => $this->reply($session, $progress->reason),
            FlowProgressStatus::NextStep => $this->askNextStep($session, $progress),
            FlowProgressStatus::Completed => $this->beginServicesPhase($session, $progress->draft),
        };
    }

    private function isSingleWordName(string $name): bool
    {
        return ! str_contains(trim($name), ' ');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function askNameConfirmation(ConversationSession $session, array $draft, string $name): void
    {
        $draft['_awaitingNameConfirmation'] = true;
        $draft['_pendingOrganizationName'] = $name;
        $this->drafts->put($session, $draft);
        $this->replyYesNo($session, $this->confirmarNombreText($name));
    }

    private function confirmarNombreText(string $name): string
    {
        return $this->botMessages->render('registro.confirmar_nombre', ['nombre' => $name])
            ?? "Tu negocio se llama *{$name}*, ¿verdad?";
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleNameConfirmation(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            $draft['organizationName'] = $draft['_pendingOrganizationName'];
            unset($draft['_awaitingNameConfirmation'], $draft['_pendingOrganizationName']);
            $this->drafts->put($session, $draft);

            $nextStep = $this->runner->currentStep($this->steps, $draft);

            if ($nextStep === null) {
                $this->beginServicesPhase($session, $draft);

                return;
            }

            $this->reply($session, ($nextStep->prompt)($draft));

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            unset($draft['_awaitingNameConfirmation'], $draft['_pendingOrganizationName']);
            $this->drafts->put($session, $draft);
            $this->reply($session, $this->botMessages->render('registro.nombre_negocio') ?? '¿Cuál es el nombre de tu negocio?');

            return;
        }

        $this->replyYesNo($session, $this->confirmarNombreText($draft['_pendingOrganizationName']));
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
        $this->reply($session, $this->botMessages->render('registro.pedir_servicio') ?? '¿Qué servicio ofrecés? (contame uno a la vez)');
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
            $this->reply($session, $this->botMessages->render('servicio.duracion', ['servicio' => $result->value]) ?? "¿Cuánto dura {$result->value}, en minutos?");

            return;
        }

        $result = $this->serviceDurationExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($session, $result->reason);

            return;
        }

        // El servicio no se empuja a $draft['services'] todavía — falta
        // saber quién lo presta. Eso lo completa
        // finishServiceResourceSelection() cuando ServiceResourceSelectionFlow
        // termine (ver dispatch en handle()).
        $draft['_pendingServiceName'] = $draft['_currentServiceName'];
        $draft['_pendingServiceDuration'] = (int) $result->value;
        unset($draft['_currentServiceName']);

        $draft = $this->resourceFlow->begin($draft, fn (string $text) => $this->reply($session, $text));
        $this->drafts->put($session, $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function finishServiceResourceSelection(ConversationSession $session, array $draft): array
    {
        $draft['services'][] = [
            'name' => $draft['_pendingServiceName'],
            'durationMinutes' => $draft['_pendingServiceDuration'],
            'resourceKeys' => $draft['_pendingServiceResourceIds'],
        ];
        unset($draft['_pendingServiceName'], $draft['_pendingServiceDuration'], $draft['_pendingServiceResourceIds']);
        $draft['_awaitingAddAnotherService'] = true;

        $this->replyYesNo($session, $this->botMessages->render('registro.otro_servicio') ?? '¿Agregás otro servicio?');

        return $draft;
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
            $this->reply($session, $this->botMessages->render('registro.nombre_servicio') ?? '¿Cuál es el nombre del servicio?');

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            unset($draft['_awaitingAddAnotherService'], $draft['_collectingServices']);
            $this->beginConfirmation($session, $draft);

            return;
        }

        $this->replyYesNo($session, $this->botMessages->render('registro.otro_servicio') ?? '¿Agregás otro servicio?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginConfirmation(ConversationSession $session, array $draft): void
    {
        $draft['_awaiting_confirmation'] = true;
        $this->drafts->put($session, $draft);
        $this->replyYesNo($session, $this->buildSummary($draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleConfirmationReply(InboundMessage $message, ConversationSession $session, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (! in_array($answer, self::YES_WORDS, true)) {
            $this->replyYesNo($session, $this->botMessages->render('registro.confirmar_alta') ?? '¿Confirmás crear tu negocio con estos datos?');

            return;
        }

        $services = array_map(
            fn (array $s) => new ServiceRegistrationData($s['name'], $s['durationMinutes'], $s['resourceKeys']),
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

        // Caso real: en un Channel que ya tenía otra Organization vinculada
        // (número de prueba compartido entre varios pilotos), la sesión de
        // este mismo teléfono había quedado memoizada a esa otra
        // organización desde antes (SingleOrganizationResolver reusa
        // session->organization_id sin volver a resolver). Sin este
        // re-attach, el resto de la conversación — ej. "quiero agregar un
        // servicio" en el negocio recién creado — seguía resolviendo contra
        // la organización vieja, y como el dueño no coincidía, la acción se
        // rechazaba en silencio y el mensaje caía a fuera de alcance.
        $this->sessions->attachOrganization($session, Organization::findOrFail($result->organizationId));

        $this->drafts->forget($session);
        $this->sessions->recordIntent($session, null);

        $this->reply($session, $this->botMessages->render('registro.listo', ['negocio' => $result->organizationName])
            ?? "¡Listo! «{$result->organizationName}» quedó registrado. Ya podés recibir reservas por acá.");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function buildSummary(array $draft): string
    {
        $resources = $draft['resources'];

        $servicesText = implode("\n", array_map(
            function (array $s) use ($resources) {
                $resourceNames = implode(', ', array_map(
                    fn (int $key) => $resources[$key]['name'],
                    $s['resourceKeys'],
                ));

                return "- {$s['name']} ({$s['durationMinutes']} min), a cargo de: {$resourceNames}";
            },
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

        $fallback = <<<TEXT
            Confirmá que estos datos son correctos:

            Negocio: {$draft['organizationName']}
            Ciudad: {$draft['city']}
            Dirección: {$draft['address']}

            Servicios:
            {$servicesText}

            Atienden:
            {$resourcesText}

            ¿Confirmás?
            TEXT;

        return $this->botMessages->render('registro.resumen', [
            'negocio' => $draft['organizationName'],
            'ciudad' => $draft['city'],
            'direccion' => $draft['address'],
            'servicios' => $servicesText,
            'recursos' => $resourcesText,
        ]) ?? $fallback;
    }

    private function reply(ConversationSession $session, string $text): void
    {
        $this->channelClient->sendTextMessage($session->channel, $session->customer_phone->value(), $text);
    }

    private function replyYesNo(ConversationSession $session, string $text): void
    {
        $this->channelClient->sendButtonsMessage($session->channel, $session->customer_phone->value(), $text, self::YES_NO_BUTTONS);
    }
}
