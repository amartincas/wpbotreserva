<?php

namespace App\Application\Conversations\Agents;

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Booking\Agenda\ProfessionalResolver;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Booking\Booking;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

/**
 * Fase 4 — agenda conversacional del profesional. Comando determinista de
 * un solo turno (igual que AdminCommandAgent/ConfirmacionAsistenciaAgent):
 * sin FlowStep/draft, todo llega resuelto en un único mensaje porque
 * DeterministicAgendaProfesionalStrategy ya garantizó forma de fecha +
 * contexto de agenda antes de clasificar este Intent.
 *
 * NotificationSenderInterface (no ChannelClientInterface): mismo criterio
 * que AdminCommandAgent/ConfirmacionAsistenciaAgent — un Agent de comando
 * determinista de un solo turno responde con send(), a diferencia de
 * RegistroNegocioAgent/GestionNegocioAgent que sostienen un flujo
 * multi-turno con FlowStep y por eso usan ChannelClientInterface.
 */
class AgendaProfesionalAgent implements AgentInterface
{
    public function __construct(
        private readonly ProfessionalResolver $professionals,
        private readonly AgendaDateResolver $dates,
        private readonly AgendaQueryService $agenda,
        private readonly NotificationSenderInterface $notifications,
        private readonly BotMessageRepository $botMessages,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        // Se vuelve a resolver acá (no se pasa por el Intent/sesión) para no
        // depender de un estado intermedio entre Strategy y Agent — mismo
        // costo que ProfessionalResolver ya paga dos veces por diseño.
        $resource = $this->professionals->resolveFor($organization, $message->fromPhone);

        if ($resource === null) {
            return;
        }

        $resolution = $this->dates->resolve($message->text, $organization);

        if ($resolution->unrecognized) {
            // No debería ocurrir: la Strategy ya garantizó forma de fecha
            // reconocible antes de clasificar este Intent. Salvaguarda
            // defensiva si ese contrato se desalinea algún día (mismo
            // criterio que el catch-all de AdminCommandAgent::handle()).
            $this->reply($organization, $message->fromPhone, $this->botMessages->render('agenda.fecha_no_reconocida')
                ?? 'No entendí la fecha. Probá con "hoy", "mañana" o una fecha como "15 de octubre".');

            return;
        }

        if ($resolution->invalidCalendarDate) {
            $this->reply($organization, $message->fromPhone, $this->botMessages->render('agenda.fecha_invalida')
                ?? 'Esa fecha no es válida.');

            return;
        }

        $bookings = $this->agenda->forDate($organization, $resolution->date, $resource);
        $label = $resolution->date->format('d/m/Y');

        if (preg_match('/\bcu[aá]nt[oa]s?\b/iu', $message->text)) {
            $this->replyCount($organization, $message->fromPhone, $bookings->count(), $label);

            return;
        }

        $this->replyDetail($organization, $message->fromPhone, $bookings, $label);
    }

    private function replyCount(Organization $organization, string $toPhone, int $count, string $label): void
    {
        if ($count === 0) {
            $this->reply($organization, $toPhone, $this->botMessages->render('agenda.cantidad_sin_citas', ['fecha' => $label])
                ?? "📅 No tenés citas para {$label}.");

            return;
        }

        $unidad = $count === 1 ? 'cita' : 'citas';

        $this->reply($organization, $toPhone, $this->botMessages->render('agenda.cantidad_con_citas', [
            'cantidad' => $count,
            'unidad' => $unidad,
            'fecha' => $label,
        ]) ?? "Tenés {$count} {$unidad} para {$label}.");
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function replyDetail(Organization $organization, string $toPhone, Collection $bookings, string $label): void
    {
        if ($bookings->isEmpty()) {
            $this->reply($organization, $toPhone, $this->botMessages->render('agenda.cantidad_sin_citas', ['fecha' => $label])
                ?? "📅 No tenés citas para {$label}.");

            return;
        }

        $bookings->loadMissing(['service', 'customer']);

        // setTimezone($organization->timezone) — mismo motivo que
        // AdminCommandAgent::formatBookingList(): starts_at releído viene en
        // config('app.timezone'), nunca en el timezone de la Organization.
        // Formato de presentación (Fase 5, ajuste final): hora (12h, sin cero
        // inicial, AM/PM), cliente y servicio — sin ID, a diferencia del
        // listado del dueño en AdminCommandAgent::formatBookingList(), que sí
        // lo necesita para los comandos "cancelar <id>"/"confirmar <id>".
        $listado = $bookings->map(fn (Booking $booking) => sprintf(
            '• %s — %s — %s',
            $booking->starts_at->setTimezone($organization->timezone)->format('g:i A'),
            $booking->customer->name ?? $booking->customer->phone->value(),
            $booking->service->name,
        ))->implode("\n");

        $this->reply($organization, $toPhone, $this->botMessages->render('agenda.detalle_header', [
            'fecha' => $label,
            'listado' => $listado,
        ]) ?? "📅 Tus citas de {$label}:\n\n{$listado}");
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}
