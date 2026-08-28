<?php

namespace App\Application\Conversations\Flows;

use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Conversational\InboundMessage;
use Closure;
use Illuminate\Support\Collection;

/**
 * Sub-conversación "¿quién presta este servicio?" — nace de
 * GestionNegocioAgent (Incremento 4), donde ya vivía esta lógica en
 * exclusiva; ahora también la usa RegistroNegocioAgent, así que dejó de
 * tener sentido mantenerla duplicada entre los dos Agents.
 *
 * No se apoya en FlowStep/ConversationalFlowRunner: ese motor está pensado
 * para "N campos fijos, uno atrás del otro" (ver su propio docblock), no
 * para un bucle con ramificación (elegir un recurso existente O dar de alta
 * uno nuevo, y repetir mientras el dueño quiera agregar otro).
 *
 * Nunca persiste el draft ni conoce el mecanismo de respuesta (WhatsApp
 * directo vs a través de una Organization) — eso lo resuelve cada Agent.
 * Cada método recibe el draft y devuelve el draft actualizado; los mensajes
 * salen por los closures $reply/$replyYesNo que pasa el Agent llamante, y
 * $onDone es la salida: se invoca cuando el dueño ya no quiere agregar más
 * recursos para este servicio, y es responsabilidad del Agent (con su draft
 * ya actualizado) decidir qué sigue.
 */
final class ServiceResourceSelectionFlow
{
    // Mismo sentinel que ya usaba GestionNegocioAgent para "dar de alta una
    // persona nueva" en vez de elegir una de las ya existentes.
    private const NEW_RESOURCE_OPTION = '0';

    /**
     * @param  string[]  $yesWords
     * @param  string[]  $noWords
     */
    public function __construct(
        private readonly ResourceCatalogInterface $catalog,
        private readonly AiFieldExtractor $resourceNameExtractor,
        private readonly WeeklyScheduleFieldExtractor $weeklyScheduleExtractor,
        private readonly array $yesWords,
        private readonly array $noWords,
        private readonly ?BotMessageRepository $botMessages = null,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function isAwaitingInput(array $draft): bool
    {
        return ($draft['_awaitingServiceResourceSelection'] ?? false) === true
            || ($draft['_awaitingNewResourceName'] ?? false) === true
            || ($draft['_awaitingNewResourceSchedule'] ?? false) === true
            || ($draft['_awaitingAddAnotherServiceResource'] ?? false) === true;
    }

    /**
     * Arranca (o reinicia) la selección de recursos para el servicio cuyo
     * nombre ya está en $draft['_pendingServiceName'] — el llamante lo deja
     * ahí antes de invocar begin().
     *
     * @param  array<string, mixed>  $draft
     * @param  Closure(string): void  $reply
     * @return array<string, mixed>
     */
    public function begin(array $draft, Closure $reply): array
    {
        $draft['_pendingServiceResourceIds'] = [];

        return $this->promptSelection($draft, $reply);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  Closure(string): void  $reply
     * @param  Closure(string): void  $replyYesNo
     * @param  Closure(array<string, mixed>): array<string, mixed>  $onDone
     * @return array<string, mixed>
     */
    public function handle(InboundMessage $message, array $draft, Closure $reply, Closure $replyYesNo, Closure $onDone): array
    {
        if (($draft['_awaitingAddAnotherServiceResource'] ?? false) === true) {
            return $this->handleAddAnotherServiceResource($message, $draft, $reply, $replyYesNo, $onDone);
        }

        if (($draft['_awaitingNewResourceSchedule'] ?? false) === true) {
            return $this->handleNewResourceSchedule($message, $draft, $reply, $replyYesNo);
        }

        if (($draft['_awaitingNewResourceName'] ?? false) === true) {
            return $this->handleNewResourceName($message, $draft, $reply);
        }

        return $this->handleServiceResourceSelection($message, $draft, $reply, $replyYesNo);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function promptSelection(array $draft, Closure $reply): array
    {
        $existing = $this->catalog->listExisting($draft);

        if ($existing === []) {
            $draft['_awaitingNewResourceName'] = true;
            $reply($this->botMessages?->render('recurso.primera_persona') ?? '¿Cómo se llama la persona o recurso que va a prestar este servicio?');

            return $draft;
        }

        $draft['_awaitingServiceResourceSelection'] = true;
        $draft['_serviceResourceOptions'] = array_column($existing, 'id');
        $reply($this->formatOptions($existing, $draft['_pendingServiceName']));

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function handleServiceResourceSelection(InboundMessage $message, array $draft, Closure $reply, Closure $replyYesNo): array
    {
        $answer = trim($message->text);

        if ($answer === self::NEW_RESOURCE_OPTION) {
            unset($draft['_awaitingServiceResourceSelection'], $draft['_serviceResourceOptions']);
            $draft['_awaitingNewResourceName'] = true;
            $reply($this->botMessages?->render('recurso.persona_nueva') ?? '¿Cómo se llama la persona o recurso nueva?');

            return $draft;
        }

        $options = $draft['_serviceResourceOptions'];

        if (! preg_match('/\d+/', $answer, $matches) || ! isset($options[((int) $matches[0]) - 1])) {
            $reply($this->botMessages?->render('recurso.opcion_invalida') ?? 'No entendí la opción. Respondé con el número de la persona o recurso, o 0 para agregar una nueva.');

            return $draft;
        }

        $chosenId = $options[((int) $matches[0]) - 1];
        $draft['_pendingServiceResourceIds'] = array_values(array_unique([...$draft['_pendingServiceResourceIds'], $chosenId]));
        unset($draft['_awaitingServiceResourceSelection'], $draft['_serviceResourceOptions']);
        $draft['_awaitingAddAnotherServiceResource'] = true;
        $replyYesNo($this->botMessages?->render('recurso.otra_persona') ?? '¿Agregás otra persona o recurso para este servicio?');

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function handleNewResourceName(InboundMessage $message, array $draft, Closure $reply): array
    {
        $result = $this->resourceNameExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $reply($result->reason);

            return $draft;
        }

        $draft['_pendingNewResourceName'] = $result->value;
        unset($draft['_awaitingNewResourceName']);
        $draft['_awaitingNewResourceSchedule'] = true;
        $reply(
            $this->botMessages?->render('recurso.horario_pregunta', ['recurso' => $result->value])
                ?? "¿Qué días y en qué horario atiende {$result->value}? (ej: \"Lunes a Viernes de 9 a 17\")"
        );

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function handleNewResourceSchedule(InboundMessage $message, array $draft, Closure $reply, Closure $replyYesNo): array
    {
        $result = $this->weeklyScheduleExtractor->extract($message->text, $draft);

        if (! $result->successful) {
            $reply($result->reason);

            return $draft;
        }

        [$draft, $resourceId] = $this->catalog->createNew($draft, $draft['_pendingNewResourceName'], $result->value);

        $draft['_pendingServiceResourceIds'] = array_values(array_unique([...$draft['_pendingServiceResourceIds'], $resourceId]));
        unset($draft['_awaitingNewResourceSchedule'], $draft['_pendingNewResourceName']);
        $draft['_awaitingAddAnotherServiceResource'] = true;
        $replyYesNo($this->botMessages?->render('recurso.otra_persona') ?? '¿Agregás otra persona o recurso para este servicio?');

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  Closure(array<string, mixed>): array<string, mixed>  $onDone
     * @return array<string, mixed>
     */
    private function handleAddAnotherServiceResource(InboundMessage $message, array $draft, Closure $reply, Closure $replyYesNo, Closure $onDone): array
    {
        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, $this->yesWords, true)) {
            unset($draft['_awaitingAddAnotherServiceResource']);

            return $this->promptSelection($draft, $reply);
        }

        if (in_array($answer, $this->noWords, true)) {
            unset($draft['_awaitingAddAnotherServiceResource']);

            return $onDone($draft);
        }

        $replyYesNo($this->botMessages?->render('recurso.otra_persona') ?? '¿Agregás otra persona o recurso para este servicio?');

        return $draft;
    }

    /**
     * @param  array<int, array{id: int|string, name: string}>  $existing
     */
    private function formatOptions(array $existing, string $serviceName): string
    {
        $options = Collection::make($existing)->values()->map(
            fn (array $resource, int $i) => ($i + 1).') '.$resource['name']
        )->implode("\n");

        return $this->botMessages?->render('recurso.quien_presta', ['servicio' => $serviceName, 'opciones' => $options])
            ?? "¿Quién va a prestar el servicio *{$serviceName}*?\n\n{$options}\n0) Agregar una persona nueva\n\nRespondé con el número.";
    }
}
