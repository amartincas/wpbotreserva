<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Booking\Booking;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

/**
 * Fase 4 — agenda conversacional. B7: es la agenda del OWNER sobre toda su
 * Organization (antes, la de un profesional identificado por
 * el teléfono propio del Resource); se consulta desde el CENTRAL. Comando determinista de
 * un solo turno (igual que AdminCommandAgent/ConfirmacionAsistenciaAgent):
 * sin FlowStep/draft, todo llega resuelto en un único mensaje porque
 * DeterministicAgendaProfesionalStrategy ya garantizó forma de fecha +
 * contexto de agenda antes de clasificar este Intent.
 *
 * B3: responde por el Channel de la sesión (ConversationReplier), no por
 * el de la Organization, y — por ser de un solo turno — limpia
 * current_intent al terminar, para que el próximo mensaje se clasifique de
 * nuevo en vez de volver acá por continuidad.
 *
 * Sin filtro por profesional ("agenda de Juan" queda fuera del MVP): cada
 * línea muestra quién atiende la cita, para que el owner la distinga.
 */
class AgendaProfesionalAgent implements AgentInterface
{
    public function __construct(
        private readonly AgendaDateResolver $dates,
        private readonly AgendaQueryService $agenda,
        private readonly ConversationReplier $replier,
        private readonly BotMessageRepository $botMessages,
        private readonly ConversationSessionRepositoryInterface $sessions,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        try {
            $this->execute($message, $session, $organization);
        } finally {
            $this->sessions->recordIntent($session, null);
        }
    }

    private function execute(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        // Defensa en profundidad: la Strategy ya exigió que quien escribe sea
        // el owner_phone de esta Organization; se vuelve a verificar acá para
        // no depender de un estado intermedio entre Strategy y Agent.
        if ($organization->owner_phone === null || $organization->owner_phone !== $message->fromPhone) {
            return;
        }

        $resolution = $this->dates->resolve($message->text, $organization);

        if ($resolution->unrecognized) {
            // No debería ocurrir: la Strategy ya garantizó forma de fecha
            // reconocible antes de clasificar este Intent. Salvaguarda
            // defensiva si ese contrato se desalinea algún día (mismo
            // criterio que el catch-all de AdminCommandAgent::handle()).
            $this->reply($session, $message->fromPhone, $this->botMessages->render('agenda.fecha_no_reconocida')
                ?? 'No entendí la fecha. Probá con "hoy", "mañana" o una fecha como "15 de octubre".');

            return;
        }

        if ($resolution->invalidCalendarDate) {
            $this->reply($session, $message->fromPhone, $this->botMessages->render('agenda.fecha_invalida')
                ?? 'Esa fecha no es válida.');

            return;
        }

        $bookings = $this->agenda->forDate($organization, $resolution->date, null);
        $label = $resolution->date->format('d/m/Y');

        if (preg_match('/\bcu[aá]nt[oa]s?\b/iu', $message->text)) {
            $this->replyCount($session, $organization, $message->fromPhone, $bookings->count(), $label);

            return;
        }

        $this->replyDetail($session, $organization, $message->fromPhone, $bookings, $label);
    }

    private function replyCount(ConversationSession $session, Organization $organization, string $toPhone, int $count, string $label): void
    {
        if ($count === 0) {
            $this->reply($session, $toPhone, $this->botMessages->render('agenda.cantidad_sin_citas', ['fecha' => $label])
                ?? "📅 No tenés citas para {$label}.");

            return;
        }

        $unidad = $count === 1 ? 'cita' : 'citas';

        $this->reply($session, $toPhone, $this->botMessages->render('agenda.cantidad_con_citas', [
            'cantidad' => $count,
            'unidad' => $unidad,
            'fecha' => $label,
        ]) ?? "Tenés {$count} {$unidad} para {$label}.");
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function replyDetail(ConversationSession $session, Organization $organization, string $toPhone, Collection $bookings, string $label): void
    {
        if ($bookings->isEmpty()) {
            $this->reply($session, $toPhone, $this->botMessages->render('agenda.cantidad_sin_citas', ['fecha' => $label])
                ?? "📅 No tenés citas para {$label}.");

            return;
        }

        $bookings->loadMissing(['service', 'customer', 'bookingResources.resource']);

        // setTimezone($organization->timezone) — mismo motivo que
        // AdminCommandAgent::formatBookingList(): starts_at releído viene en
        // config('app.timezone'), nunca en el timezone de la Organization.
        // Formato de presentación (Fase 5, ajuste final): hora (12h, sin cero
        // inicial, AM/PM), cliente y servicio — sin ID, a diferencia del
        // listado del dueño en AdminCommandAgent::formatBookingList(), que sí
        // lo necesita para los comandos "cancelar <id>"/"confirmar <id>".
        $listado = $bookings->map(fn (Booking $booking) => implode(' — ', array_filter([
            '• '.$booking->starts_at->setTimezone($organization->timezone)->format('g:i A'),
            $booking->customer->name ?? $booking->customer->phone->value(),
            $booking->service->name,
            // B7: quién atiende la cita, cuando la reserva tiene un recurso
            // asignado — la agenda ya no es la de un único profesional.
            $booking->bookingResources->first()?->resource?->display_name,
        ])))->implode("\n");

        $this->reply($session, $toPhone, $this->botMessages->render('agenda.detalle_header', [
            'fecha' => $label,
            'listado' => $listado,
        ]) ?? "📅 Tus citas de {$label}:\n\n{$listado}");
    }

    private function reply(ConversationSession $session, string $toPhone, string $text): void
    {
        $this->replier->send($session, $toPhone, $text);
    }
}
