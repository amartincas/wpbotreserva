<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\AddResourceCommand;
use App\Application\Tenancy\AddServiceCommand;
use App\Application\Tenancy\ReplaceResourceScheduleCommand;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

/**
 * Agente Gestión de Negocio (Incremento 4, puntos D/E de la prueba real):
 * el dueño de un negocio YA registrado agrega un servicio o cambia el
 * horario de un recurso — ninguna de las dos cosas existía antes.
 * Disparado por DeterministicBusinessManagementStrategy (nunca IA, mismo
 * criterio que AdminCommand — acción sensible de un único dueño).
 *
 * El primer mensaje se revisa contra las mismas frases específicas que
 * reconoce el classifier: si ya dice "agregar servicio" o "cambiar
 * horario", salta directo a la pregunta que corresponde — no tiene
 * sentido volver a preguntar qué quiere hacer si ya lo dijo (caso real
 * reportado: se esperaba avanzar directo, no repetir la pregunta). El
 * botón de elección queda solo para un disparador genérico ("administrar
 * mi negocio") que no especifica cuál de las dos acciones.
 */
class GestionNegocioAgent implements AgentInterface
{
    private const YES_WORDS = ['si', 'sí', 'confirmo', 'dale', 'ok', 'okay'];

    private const NO_WORDS = ['no', 'nel', 'nop', 'no gracias'];

    private const ACTION_ADD_SERVICE = 'agregar_servicio';

    private const ACTION_CHANGE_SCHEDULE = 'cambiar_horario';

    private const ACTION_BUTTONS = [
        ['id' => self::ACTION_ADD_SERVICE, 'title' => 'Agregar servicio'],
        ['id' => self::ACTION_CHANGE_SCHEDULE, 'title' => 'Cambiar horario'],
    ];

    // Subconjunto de DeterministicBusinessManagementStrategy::TRIGGER_PHRASES
    // — solo las que ya dicen específicamente cuál de las dos acciones
    // quiere el dueño (las genéricas, como "administrar mi negocio", se
    // quedan fuera a propósito y sí preguntan con botones).
    private const ADD_SERVICE_TRIGGER_PHRASES = [
        'agregar servicio',
        'agregar un servicio',
        'agregar un servicio nuevo',
        'nuevo servicio',
        'registrar otro servicio',
        'registrar otro servicios',
    ];

    private const CHANGE_SCHEDULE_TRIGGER_PHRASES = [
        'cambiar horario',
        'cambiar el horario',
        'modificar horario',
        'cambiar la agenda',
    ];

    private const YES_NO_BUTTONS = [
        ['id' => 'si', 'title' => 'Sí'],
        ['id' => 'no', 'title' => 'No'],
    ];

    // Sentinel de texto para "quiero dar de alta una persona/recurso nueva"
    // en vez de elegir una de las ya existentes — mismo criterio que "0) Salir"
    // en otros menús numerados de este proyecto.
    private const NEW_RESOURCE_OPTION = '0';

    private readonly AiFieldExtractor $serviceNameExtractor;

    private readonly AiFieldExtractor $serviceDurationExtractor;

    private readonly AiFieldExtractor $resourceNameExtractor;

    private readonly WeeklyScheduleFieldExtractor $weeklyScheduleExtractor;

    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
        private readonly AddServiceCommand $addService,
        private readonly AddResourceCommand $addResource,
        private readonly ReplaceResourceScheduleCommand $replaceSchedule,
        AiServiceInterface $ai,
    ) {
        $this->serviceNameExtractor = new AiFieldExtractor($ai, 'nombre del servicio', 'Un servicio nuevo que va a ofrecer el negocio.');
        $this->serviceDurationExtractor = new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.');
        $this->resourceNameExtractor = new AiFieldExtractor($ai, 'nombre del recurso', 'El nombre de la persona o recurso que va a atender.');
        $this->weeklyScheduleExtractor = new WeeklyScheduleFieldExtractor($ai);
    }

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaitingServiceConfirmation'] ?? false) === true) {
            $this->handleServiceConfirmation($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingAddAnotherServiceResource'] ?? false) === true) {
            $this->handleAddAnotherServiceResource($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingNewResourceSchedule'] ?? false) === true) {
            $this->handleNewResourceSchedule($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingNewResourceName'] ?? false) === true) {
            $this->handleNewResourceName($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingServiceResourceSelection'] ?? false) === true) {
            $this->handleServiceResourceSelection($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingServiceDuration'] ?? false) === true) {
            $this->handleServiceDuration($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingServiceName'] ?? false) === true) {
            $this->handleServiceName($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingScheduleConfirmation'] ?? false) === true) {
            $this->handleScheduleConfirmation($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingNewSchedule'] ?? false) === true) {
            $this->handleNewSchedule($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingResourceSelection'] ?? false) === true) {
            $this->handleResourceSelection($message, $session, $organization, $draft);

            return;
        }

        if (($draft['_awaitingAction'] ?? false) === true) {
            $this->handleActionChoice($message, $session, $organization, $draft);

            return;
        }

        // Primer mensaje del flujo (disparó Intent::GestionNegocio). Si ya
        // es una frase específica, no hace falta preguntar de nuevo.
        $trigger = mb_strtolower(trim($message->text));

        if (in_array($trigger, self::ADD_SERVICE_TRIGGER_PHRASES, true)) {
            $draft['_awaitingServiceName'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, '¿Cuál es el nombre del servicio nuevo?');

            return;
        }

        if (in_array($trigger, self::CHANGE_SCHEDULE_TRIGGER_PHRASES, true)) {
            $this->beginScheduleChange($session, $organization, $message->fromPhone, $draft);

            return;
        }

        // Disparador genérico ("administrar mi negocio", etc.) — ahí sí
        // hace falta preguntar cuál de las dos acciones quiere.
        $draft['_awaitingAction'] = true;
        $this->drafts->put($session, $draft);
        $this->notifications->sendButtons($organization, $message->fromPhone, '¿Qué querés hacer?', self::ACTION_BUTTONS);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleActionChoice(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if ($answer === self::ACTION_ADD_SERVICE) {
            unset($draft['_awaitingAction']);
            $draft['_awaitingServiceName'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, '¿Cuál es el nombre del servicio nuevo?');

            return;
        }

        if ($answer === self::ACTION_CHANGE_SCHEDULE) {
            unset($draft['_awaitingAction']);
            $this->beginScheduleChange($session, $organization, $message->fromPhone, $draft);

            return;
        }

        $this->notifications->sendButtons($organization, $message->fromPhone, 'No entendí. ¿Qué querés hacer?', self::ACTION_BUTTONS);
    }

    // --- Agregar servicio ---------------------------------------------

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceName(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->serviceNameExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($organization, $message->fromPhone, $result->reason);

            return;
        }

        $draft['_pendingServiceName'] = $result->value;
        unset($draft['_awaitingServiceName']);
        $draft['_awaitingServiceDuration'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($organization, $message->fromPhone, "¿Cuánto dura {$result->value}, en minutos?");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceDuration(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->serviceDurationExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($organization, $message->fromPhone, $result->reason);

            return;
        }

        $draft['_pendingServiceDuration'] = (int) $result->value;
        unset($draft['_awaitingServiceDuration']);
        $this->beginServiceResourceSelection($session, $organization, $message->fromPhone, $draft);
    }

    /**
     * Cada servicio tiene su/sus propios recursos, nunca "todos los que ya
     * existen por default" (caso real: agregar un servicio nuevo lo dejaba
     * habilitado para cualquier recurso del negocio sin preguntar, y el
     * dueño aclaró que no hay que asumir eso — un servicio puede tener uno
     * o varios recursos, pero son autónomos entre sí).
     *
     * Corrección post-prueba real (segunda ronda): aun con un solo recurso
     * existente, el dueño espera que el bot pregunte y ofrezca la opción de
     * dar de alta una persona nueva — "debe comportarse tal cual como se
     * crea el primer servicio, debe preguntar el recurso y su horario". Así
     * que ya no se salta la pregunta con <=1 recurso: siempre se pregunta,
     * salvo que el negocio no tenga NINGÚN recurso todavía (ahí no hay nada
     * que elegir, se va directo a dar de alta uno).
     *
     * @param  array<string, mixed>  $draft
     */
    private function beginServiceResourceSelection(ConversationSession $session, Organization $organization, string $toPhone, array $draft): void
    {
        $resources = $organization->resources()->orderBy('id')->get();

        $draft['_pendingServiceResourceIds'] = $draft['_pendingServiceResourceIds'] ?? [];

        if ($resources->isEmpty()) {
            $draft['_awaitingNewResourceName'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $toPhone, '¿Cómo se llama la persona o recurso que va a prestar este servicio?');

            return;
        }

        $draft['_awaitingServiceResourceSelection'] = true;
        $draft['_serviceResourceOptions'] = $resources->pluck('id')->all();
        $this->drafts->put($session, $draft);
        $this->reply($organization, $toPhone, $this->formatServiceResourceOptions($resources, $draft['_pendingServiceName']));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceResourceSelection(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = trim($message->text);

        if ($answer === self::NEW_RESOURCE_OPTION) {
            unset($draft['_awaitingServiceResourceSelection'], $draft['_serviceResourceOptions']);
            $draft['_awaitingNewResourceName'] = true;
            $this->drafts->put($session, $draft);
            $this->reply($organization, $message->fromPhone, '¿Cómo se llama la persona o recurso nueva?');

            return;
        }

        $options = $draft['_serviceResourceOptions'];

        if (! preg_match('/\d+/', $answer, $matches) || ! isset($options[((int) $matches[0]) - 1])) {
            $this->reply($organization, $message->fromPhone, 'No entendí la opción. Respondé con el número de la persona o recurso, o 0 para agregar una nueva.');

            return;
        }

        $chosenId = $options[((int) $matches[0]) - 1];
        $draft['_pendingServiceResourceIds'] = array_values(array_unique([...$draft['_pendingServiceResourceIds'], $chosenId]));
        unset($draft['_awaitingServiceResourceSelection'], $draft['_serviceResourceOptions']);
        $draft['_awaitingAddAnotherServiceResource'] = true;
        $this->drafts->put($session, $draft);
        $this->replyYesNo($organization, $message->fromPhone, '¿Agregás otra persona o recurso para este servicio?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleNewResourceName(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->resourceNameExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($organization, $message->fromPhone, $result->reason);

            return;
        }

        $draft['_pendingNewResourceName'] = $result->value;
        unset($draft['_awaitingNewResourceName']);
        $draft['_awaitingNewResourceSchedule'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($organization, $message->fromPhone, "¿Qué días y en qué horario atiende {$result->value}? (ej: \"Lunes a Viernes de 9 a 17\")");
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleNewResourceSchedule(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->weeklyScheduleExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($organization, $message->fromPhone, $result->reason);

            return;
        }

        $resource = $this->addResource->handle(
            $organization,
            new ResourceRegistrationData($draft['_pendingNewResourceName'], $result->value),
        );

        $draft['_pendingServiceResourceIds'] = array_values(array_unique([...$draft['_pendingServiceResourceIds'], $resource->id]));
        unset($draft['_awaitingNewResourceSchedule'], $draft['_pendingNewResourceName']);
        $draft['_awaitingAddAnotherServiceResource'] = true;
        $this->drafts->put($session, $draft);
        $this->replyYesNo($organization, $message->fromPhone, '¿Agregás otra persona o recurso para este servicio?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleAddAnotherServiceResource(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            unset($draft['_awaitingAddAnotherServiceResource']);
            $this->beginServiceResourceSelection($session, $organization, $message->fromPhone, $draft);

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            unset($draft['_awaitingAddAnotherServiceResource']);
            $this->beginServiceConfirmation($session, $organization, $message->fromPhone, $draft);

            return;
        }

        $this->replyYesNo($organization, $message->fromPhone, '¿Agregás otra persona o recurso para este servicio?');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginServiceConfirmation(ConversationSession $session, Organization $organization, string $toPhone, array $draft): void
    {
        $draft['_awaitingServiceConfirmation'] = true;
        $this->drafts->put($session, $draft);
        $this->replyYesNo($organization, $toPhone, $this->serviceConfirmationText($draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceConfirmation(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            $this->addService->handle(
                $organization,
                new ServiceRegistrationData($draft['_pendingServiceName'], $draft['_pendingServiceDuration']),
                $draft['_pendingServiceResourceIds'],
            );

            $this->drafts->forget($session);
            $this->sessions->recordIntent($session, null);
            $this->reply($organization, $message->fromPhone, "¡Listo! Agregué *{$draft['_pendingServiceName']}* a tu negocio.");

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            $this->drafts->forget($session);
            $this->sessions->recordIntent($session, null);
            $this->reply($organization, $message->fromPhone, 'Ok, no agregué nada.');

            return;
        }

        $this->replyYesNo($organization, $message->fromPhone, $this->serviceConfirmationText($draft));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function serviceConfirmationText(array $draft): string
    {
        $resourceNames = Resource::whereIn('id', $draft['_pendingServiceResourceIds'])
            ->pluck('display_name')
            ->implode(', ');

        return "Agrego el servicio *{$draft['_pendingServiceName']}* ({$draft['_pendingServiceDuration']} min), a cargo de: {$resourceNames}. ¿Confirmás?";
    }

    /**
     * @param  Collection<int, Resource>  $resources
     */
    private function formatServiceResourceOptions(Collection $resources, string $serviceName): string
    {
        $options = $resources->values()->map(
            fn (Resource $resource, int $i) => ($i + 1).') '.$resource->display_name
        )->implode("\n");

        return "¿Quién va a prestar el servicio *{$serviceName}*?\n\n{$options}\n0) Agregar una persona nueva\n\nRespondé con el número.";
    }

    // --- Cambiar horario -------------------------------------------------

    /**
     * @param  array<string, mixed>  $draft
     */
    private function beginScheduleChange(ConversationSession $session, Organization $organization, string $toPhone, array $draft): void
    {
        $resources = $organization->resources()->orderBy('id')->get();

        if ($resources->count() > 1) {
            $draft['_awaitingResourceSelection'] = true;
            $draft['_resourceOptions'] = $resources->pluck('id')->all();
            $this->drafts->put($session, $draft);
            $this->reply($organization, $toPhone, $this->formatResourceOptions($resources));

            return;
        }

        $resource = $resources->first();
        $draft['resourceId'] = $resource->id;
        $draft['_awaitingNewSchedule'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($organization, $toPhone, $this->newScheduleQuestion($resource));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleResourceSelection(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $options = $draft['_resourceOptions'];

        if (! preg_match('/\d+/', $message->text, $matches) || ! isset($options[((int) $matches[0]) - 1])) {
            $this->reply($organization, $message->fromPhone, 'No entendí la opción. Respondé con el número de la persona o recurso.');

            return;
        }

        $resource = Resource::findOrFail($options[((int) $matches[0]) - 1]);
        $draft['resourceId'] = $resource->id;
        unset($draft['_awaitingResourceSelection'], $draft['_resourceOptions']);
        $draft['_awaitingNewSchedule'] = true;
        $this->drafts->put($session, $draft);
        $this->reply($organization, $message->fromPhone, $this->newScheduleQuestion($resource));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleNewSchedule(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $result = $this->weeklyScheduleExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $this->reply($organization, $message->fromPhone, $result->reason);

            return;
        }

        $draft['_pendingSchedule'] = $result->value;
        unset($draft['_awaitingNewSchedule']);
        $draft['_awaitingScheduleConfirmation'] = true;
        $this->drafts->put($session, $draft);

        $resource = Resource::findOrFail($draft['resourceId']);
        $this->replyYesNo(
            $organization,
            $message->fromPhone,
            "Nuevo horario de *{$resource->display_name}*: {$this->formatSchedule($result->value)}.\n\nEsto reemplaza el horario actual. ¿Confirmás?",
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleScheduleConfirmation(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));
        $resource = Resource::findOrFail($draft['resourceId']);

        if (in_array($answer, self::YES_WORDS, true)) {
            $this->replaceSchedule->handle($resource, $draft['_pendingSchedule']);

            $this->drafts->forget($session);
            $this->sessions->recordIntent($session, null);
            $this->reply($organization, $message->fromPhone, "¡Listo! Actualicé el horario de *{$resource->display_name}*.");

            return;
        }

        if (in_array($answer, self::NO_WORDS, true)) {
            $this->drafts->forget($session);
            $this->sessions->recordIntent($session, null);
            $this->reply($organization, $message->fromPhone, 'Ok, no cambié el horario.');

            return;
        }

        $this->replyYesNo(
            $organization,
            $message->fromPhone,
            "Nuevo horario de *{$resource->display_name}*: {$this->formatSchedule($draft['_pendingSchedule'])}.\n\nEsto reemplaza el horario actual. ¿Confirmás?",
        );
    }

    /**
     * @param  Collection<int, Resource>  $resources
     */
    private function formatResourceOptions(Collection $resources): string
    {
        $options = $resources->values()->map(
            fn (Resource $resource, int $i) => ($i + 1).') '.$resource->display_name
        )->implode("\n");

        return "¿A quién le cambiás el horario?\n\n{$options}\n\nRespondé con el número.";
    }

    private function newScheduleQuestion(Resource $resource): string
    {
        return "¿Qué días y en qué horario atiende ahora {$resource->display_name}? (ej: \"Lunes a Viernes de 9 a 17\")\n\nEsto va a reemplazar el horario actual completo.";
    }

    /**
     * @param  WeeklyScheduleSlot[]  $schedule
     */
    private function formatSchedule(array $schedule): string
    {
        return implode(', ', array_map(
            fn (WeeklyScheduleSlot $slot) => "día {$slot->weekday} de {$slot->startTime} a {$slot->endTime}",
            $schedule
        ));
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }

    private function replyYesNo(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->sendButtons($organization, $toPhone, $text, self::YES_NO_BUTTONS);
    }
}
