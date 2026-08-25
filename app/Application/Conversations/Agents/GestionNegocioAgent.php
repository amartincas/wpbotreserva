<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\AddServiceCommand;
use App\Application\Tenancy\ReplaceResourceScheduleCommand;
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
 * El primer turno siempre pregunta con botones qué quiere hacer, sin
 * depender de qué frase exacta disparó el Intent — así la estrategia de
 * clasificación no necesita distinguir "agregar servicio" de "cambiar
 * horario" con precisión, solo reconocer que el dueño quiere gestionar
 * algo (ver DeterministicBusinessManagementStrategy).
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

    private const YES_NO_BUTTONS = [
        ['id' => 'si', 'title' => 'Sí'],
        ['id' => 'no', 'title' => 'No'],
    ];

    private readonly AiFieldExtractor $serviceNameExtractor;

    private readonly AiFieldExtractor $serviceDurationExtractor;

    private readonly WeeklyScheduleFieldExtractor $weeklyScheduleExtractor;

    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
        private readonly AddServiceCommand $addService,
        private readonly ReplaceResourceScheduleCommand $replaceSchedule,
        AiServiceInterface $ai,
    ) {
        $this->serviceNameExtractor = new AiFieldExtractor($ai, 'nombre del servicio', 'Un servicio nuevo que va a ofrecer el negocio.');
        $this->serviceDurationExtractor = new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.');
        $this->weeklyScheduleExtractor = new WeeklyScheduleFieldExtractor($ai);
    }

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaitingServiceConfirmation'] ?? false) === true) {
            $this->handleServiceConfirmation($message, $session, $organization, $draft);

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

        // Primer mensaje del flujo (disparó Intent::GestionNegocio) — no
        // interpreta el texto que lo disparó, pregunta directo con botones.
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
        $draft['_awaitingServiceConfirmation'] = true;
        $this->drafts->put($session, $draft);
        $this->replyYesNo(
            $organization,
            $message->fromPhone,
            "Agrego el servicio *{$draft['_pendingServiceName']}* ({$draft['_pendingServiceDuration']} min) a tu negocio, ¿confirmás?",
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function handleServiceConfirmation(InboundMessage $message, ConversationSession $session, Organization $organization, array $draft): void
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::YES_WORDS, true)) {
            $this->addService->handle($organization, new ServiceRegistrationData(
                $draft['_pendingServiceName'],
                $draft['_pendingServiceDuration'],
            ));

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

        $this->replyYesNo(
            $organization,
            $message->fromPhone,
            "Agrego el servicio *{$draft['_pendingServiceName']}* ({$draft['_pendingServiceDuration']} min) a tu negocio, ¿confirmás?",
        );
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
