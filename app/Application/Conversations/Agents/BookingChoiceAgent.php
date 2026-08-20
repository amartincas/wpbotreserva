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
            $this->reply($organization, $message->fromPhone, 'Veo que ya tenés reservas activas. ¿Querés hacer una reserva nueva o gestionar una que ya tenés? (nueva/gestionar)');

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

        $this->reply($organization, $message->fromPhone, 'No entendí. Respondé "nueva" o "gestionar".');
    }

    private function startReserva(ConversationSession $session, Organization $organization, string $toPhone, string $originalText): void
    {
        $this->sessions->recordIntent($session, Intent::Reserva);

        // Reintenta extraer la fecha del mensaje ORIGINAL (el que disparó la
        // pregunta de desambiguación, no la respuesta "nueva") — si ya la
        // dijo ahí, salta directo a preguntar el nombre en vez de pedirle
        // la fecha de nuevo. Mismo DateFieldExtractor que usa ReservaAgent,
        // así que el resultado (o el fallo) es idéntico al que tendría si
        // hubiera llegado directo a ese Agent.
        $result = $this->dateExtractor->extract($originalText, []);

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
