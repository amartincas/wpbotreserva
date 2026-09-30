<?php

namespace App\Application\Booking\Agenda;

use App\Domain\Booking\Booking;
use App\Domain\Booking\ValueObjects\CalendarDayRange;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Consulta compartida de agenda por fecha (Fase 4) — generaliza el query
 * que antes vivía solo en AdminCommandAgent::listForDate() (dueño,
 * organization-wide), agregándole un filtro opcional por Resource
 * (profesional). Nunca consulta Resource ni Booking de forma global:
 * siempre parte de Organization->bookings(), igual que el resto del
 * código de este proyecto (OrganizationScope es no-op — el aislamiento
 * real depende de este traversal explícito).
 *
 * CalendarDayRange (no whereDate) — corrección transversal de timezone:
 * whereDate() compara contra el string crudo persistido (en
 * config('app.timezone')), nunca contra el timezone de la Organization que
 * $date puede traer adjunto — whereDate() habría fallado en encontrar
 * reservas reales para cualquier Organization con timezone distinto al del
 * servidor.
 */
class AgendaQueryService
{
    /**
     * @return Collection<int, Booking>
     */
    public function forDate(Organization $organization, CarbonImmutable $date, ?Resource $resource = null): Collection
    {
        $range = CalendarDayRange::forDate($date);

        $query = $organization->bookings()
            ->where('starts_at', '>=', $range->start)
            ->where('starts_at', '<', $range->end)
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->orderBy('starts_at');

        if ($resource !== null) {
            $query->whereHas('bookingResources', fn ($q) => $q->where('resource_id', $resource->id));
        }

        return $query->get();
    }
}
