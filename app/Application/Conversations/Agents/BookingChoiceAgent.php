<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Flows\DateFieldExtractor;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Organization;

/**
 * Único trabajo: cuando InboundMessageRouter detecta que un cliente con
 * reservas activas arranca una conversación nueva pidiendo (ambiguamente)
 * reservar o gestionar, pregunta directo en vez de dejar que la IA
 * adivine — decisión tomada junto con el usuario tras un caso real donde
 * adivinar mal dejaba al cliente atrapado a mitad de otro flujo sin poder
 * salir. Una vez que el cliente responde, entrega el Intent correcto y el
 * flujo elegido arranca limpio desde su propio primer paso — nunca hay
 * "cambio de opinión a mitad de camino" porque acá nunca se avanza más
 * allá de esta única pregunta.
 */
class BookingChoiceAgent implements AgentInterface
{
    private const NUEVA_WORDS = ['nueva', 'nuevo', 'reservar', 'agendar', 'una nueva', 'un turno nuevo'];

    private const GESTIONAR_WORDS = ['gestionar', 'gestion', 'gestión', 'existente', 'administrar', 'la que tengo', 'una que tengo'];

    // Ids que ya son valores válidos de NUEVA_WORDS/GESTIONAR_WORDS —
    // mismo criterio en todos los Agents con botones (ver RegistroNegocioAgent).
    private const NUEVA_GESTIONAR_BUTTONS = [
        ['id' => 'nueva', 'title' => 'Nueva reserva'],
        ['id' => 'gestionar', 'title' => 'Gestionar reserva'],
    ];

    private readonly DateFieldExtractor $dateExtractor;

    public function __construct(
        private readonly ConversationDraftRepositoryInterface $drafts,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly NotificationSenderInterface $notifications,
        AiServiceInterface $ai,
    ) {
        $this->dateExtractor = new DateFieldExtractor($ai);
    }

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $draft = $this->drafts->get($session);

        if (($draft['_awaiting_choice'] ?? false) !== true) {
            // Guarda el mensaje que disparó la pregunta (ej. "quiero una
            // reserva para el 24") — caso real: si el cliente ya dijo la
            // fecha ahí, "nueva" abajo la reaprovecha en vez de hacerle
            // repetir lo que ya dijo.
            $this->drafts->put($session, ['_awaiting_choice' => true, '_originalText' => $message->text]);
            $this->notifications->sendButtons(
                $organization,
                $message->fromPhone,
                'Veo que ya tenés reservas activas. ¿Querés hacer una reserva nueva o gestionar una que ya tenés?',
                self::NUEVA_GESTIONAR_BUTTONS,
            );

            return;
        }

        $answer = mb_strtolower(trim($message->text));

        if (in_array($answer, self::NUEVA_WORDS, true)) {
            $this->startReserva($session, $organization, $message->fromPhone, $draft['_originalText'] ?? '');

            return;
        }

        if (in_array($answer, self::GESTIONAR_WORDS, true)) {
            $this->drafts->forget($session);
            $this->sessions->recordIntent($session, Intent::GestionReserva);
            $this->reply($organization, $message->fromPhone, 'Dale, contame qué necesitás con tu reserva.');

            return;
        }

        $this->notifications->sendButtons($organization, $message->fromPhone, 'No entendí. ¿Nueva reserva o gestionar una que ya tenés?', self::NUEVA_GESTIONAR_BUTTONS);
    }

    private function startReserva(ConversationSession $session, Organization $organization, string $toPhone, string $originalText): void
    {
        $this->sessions->recordIntent($session, Intent::Reserva);

        // Reintenta extraer la fecha del mensaje ORIGINAL (el que disparó la
        // pregunta de desambiguación, no la respuesta "nueva") — si ya la
        // dijo ahí, se la deja pre-cargada en el draft para que ReservaAgent
        // no vuelva a pedirla (ConversationalFlowRunner salta cualquier
        // FlowStep cuya key ya esté en el draft). Mismo DateFieldExtractor
        // que usa ReservaAgent, así que el resultado (o el fallo) es
        // idéntico al que tendría si hubiera llegado directo a ese Agent.
        $result = $this->dateExtractor->extract($originalText, []);
        $prefilled = $result->successful ? ['date' => $result->value] : [];

        // Incremento 4: si el negocio tiene más de un Service, hay que
        // preguntar cuál ANTES de fecha/nombre — no se puede saltar directo.
        // Deja el draft en el mismo estado que ReservaAgent::askServiceSelection()
        // dejaría (_awaiting_service_selection + _serviceOptions, más la
        // fecha ya precargada si se pudo extraer), para que el próximo
        // mensaje del cliente lo resuelva ReservaAgent::handleServiceSelection()
        // sin que este Agent necesite conocer esa lógica en detalle.
        $services = $organization->services()->orderBy('id')->get();

        if ($services->count() > 1) {
            $this->drafts->put($session, [
                ...$prefilled,
                '_awaiting_service_selection' => true,
                '_serviceOptions' => $services->pluck('id')->all(),
            ]);
            $options = $services->values()->map(fn ($service, int $i) => ($i + 1).') '.$service->name)->implode("\n");
            $this->reply($organization, $toPhone, "¿Qué servicio querés?\n\n{$options}\n\nRespondé con el número.");

            return;
        }

        if ($result->successful) {
            $this->drafts->put($session, ['_started' => true, 'date' => $result->value]);
            $this->reply($organization, $toPhone, '¿A nombre de quién hago la reserva?');

            return;
        }

        // _started=true: esta pregunta ya cuenta como el primer turno real
        // de ReservaAgent, la respuesta del cliente no debe reinterpretarse
        // como si disparara el flujo de nuevo.
        $this->drafts->put($session, ['_started' => true]);
        $this->reply($organization, $toPhone, '¿Para qué día querés el turno?');
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
