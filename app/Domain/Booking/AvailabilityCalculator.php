<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\Booking\ValueObjects\TimeRange;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\ScheduleException;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Motor de disponibilidad (Parte III §8). Cubre el caso 0-1 recurso del MVP
 * (Parte II R2) — multi-recurso simultáneo (ej. dentista + consultorio a la
 * vez) queda para cuando un piloto real lo necesite, sobre este mismo
 * esquema (service_resource_requirements ya lo soporta).
 *
 * Simplificaciones deliberadas de esta primera versión, documentadas para no
 * confundirlas con descuido:
 * - Los slots candidatos se generan alineados a `duration_minutes` del
 *   servicio (no a una grilla fija de 15 min) — cambiar el paso es un ajuste
 *   local a generateCandidateSlots(), no un rediseño.
 * - Una excepción de horario (ScheduleException) reemplaza por completo el
 *   horario base del día cuando es SPECIAL_HOURS, en vez de "recomponer"
 *   parcialmente con el horario normal — evita un motor de composición de
 *   rangos mucho más complejo sin un caso de uso real todavía que lo pida.
 * - El buffer de una reserva YA EXISTENTE se toma del `buffer_minutes`
 *   ACTUAL de su servicio (no fue snapshoteado en el Hito 1). Si ese valor
 *   cambia después de crear la reserva, el cálculo de conflictos futuro usa
 *   el nuevo valor — inconsistencia menor, sin riesgo de doble reserva,
 *   reportada como posible mejora de seguimiento, no bloqueante.
 */
class AvailabilityCalculator implements AvailabilityCalculatorInterface
{
    public function availableSlots(
        Service $service,
        Location $location,
        CarbonImmutable $date,
        ?Resource $resource = null,
    ): Collection {
        if ($resource) {
            return $this->computeSlots($resource, $service, $location, $date);
        }

        if ($service->resourceRequirements()->doesntExist()) {
            return $this->computeSlots(null, $service, $location, $date);
        }

        return $this->candidateResources($service, $location)
            ->flatMap(fn (Resource $candidate) => $this->computeSlots($candidate, $service, $location, $date))
            ->values();
    }

    private function computeSlots(?Resource $resource, Service $service, Location $location, CarbonImmutable $date): Collection
    {
        $windows = $this->resolveOpenWindows($resource, $location, $date);
        $candidateSlots = $this->generateCandidateSlots($windows, $service->duration_minutes);

        return collect($candidateSlots)
            ->filter(fn (TimeRange $slot) => $this->isSlotAvailable($slot, $resource, $service, $location))
            ->map(fn (TimeRange $slot) => new AvailableSlot($slot, $resource))
            ->values();
    }

    private function candidateResources(Service $service, Location $location): Collection
    {
        return $service->resources()
            ->where('is_active', true)
            ->where(function ($query) use ($location) {
                $query->where('location_id', $location->id)->orWhereNull('location_id');
            })
            ->get();
    }

    /**
     * Horario semanal propio del recurso, o heredado del Location si no
     * tiene uno propio (Parte I §8), con las schedule_exceptions del día
     * aplicadas encima.
     *
     * @return TimeRange[]
     */
    private function resolveOpenWindows(?Resource $resource, Location $location, CarbonImmutable $date): array
    {
        $weekday = $date->dayOfWeek;

        $rows = $resource
            ? ResourceSchedule::where('resource_id', $resource->id)->where('weekday', $weekday)->get()
            : collect();

        if ($rows->isEmpty()) {
            $rows = ResourceSchedule::where('location_id', $location->id)->where('weekday', $weekday)->get();
        }

        $baseWindows = $rows
            ->map(fn (ResourceSchedule $row) => new TimeRange(
                $date->setTimeFromTimeString($row->start_time),
                $date->setTimeFromTimeString($row->end_time),
            ))
            ->all();

        $exception = $this->mostSpecificException($resource, $location, $date);

        if (! $exception) {
            return $baseWindows;
        }

        if (! $exception->is_available) {
            if (! $exception->start_time || ! $exception->end_time) {
                return []; // Día completo bloqueado (festivo/bloqueo sin rango horario).
            }

            $blocked = new TimeRange(
                $date->setTimeFromTimeString($exception->start_time),
                $date->setTimeFromTimeString($exception->end_time),
            );

            return $this->subtractRange($baseWindows, $blocked);
        }

        // SPECIAL_HOURS disponible: reemplaza el horario base del día, no lo combina.
        if ($exception->start_time && $exception->end_time) {
            return [new TimeRange(
                $date->setTimeFromTimeString($exception->start_time),
                $date->setTimeFromTimeString($exception->end_time),
            )];
        }

        return $baseWindows;
    }

    /**
     * El más específico gana: recurso > local > organización completa.
     */
    private function mostSpecificException(?Resource $resource, Location $location, CarbonImmutable $date): ?ScheduleException
    {
        $dateString = $date->toDateString();

        if ($resource) {
            $resourceLevel = ScheduleException::where('resource_id', $resource->id)
                ->where('date', $dateString)
                ->first();

            if ($resourceLevel) {
                return $resourceLevel;
            }
        }

        $locationLevel = ScheduleException::where('location_id', $location->id)
            ->whereNull('resource_id')
            ->where('date', $dateString)
            ->first();

        if ($locationLevel) {
            return $locationLevel;
        }

        return ScheduleException::where('organization_id', $location->organization_id)
            ->whereNull('location_id')
            ->whereNull('resource_id')
            ->where('date', $dateString)
            ->first();
    }

    /**
     * @param  TimeRange[]  $windows
     * @return TimeRange[]
     */
    private function subtractRange(array $windows, TimeRange $blocked): array
    {
        $result = [];

        foreach ($windows as $window) {
            if (! $window->overlaps($blocked)) {
                $result[] = $window;

                continue;
            }

            $leftEnd = $blocked->start->lessThan($window->end) ? $blocked->start : $window->end;
            if ($leftEnd->greaterThan($window->start)) {
                $result[] = new TimeRange($window->start, $leftEnd);
            }

            $rightStart = $blocked->end->greaterThan($window->start) ? $blocked->end : $window->start;
            if ($window->end->greaterThan($rightStart)) {
                $result[] = new TimeRange($rightStart, $window->end);
            }
        }

        return $result;
    }

    /**
     * @param  TimeRange[]  $windows
     * @return TimeRange[]
     */
    private function generateCandidateSlots(array $windows, int $durationMinutes): array
    {
        $slots = [];

        foreach ($windows as $window) {
            $cursor = $window->start;

            while (true) {
                $slotEnd = $cursor->addMinutes($durationMinutes);
                if ($slotEnd->greaterThan($window->end)) {
                    break;
                }

                $slots[] = new TimeRange($cursor, $slotEnd);
                $cursor = $slotEnd;
            }
        }

        return $slots;
    }

    private function isSlotAvailable(TimeRange $slot, ?Resource $resource, Service $service, Location $location): bool
    {
        $activeStatuses = [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value];

        if ($resource) {
            $conflicting = Booking::query()
                ->whereIn('status', $activeStatuses)
                ->whereDate('starts_at', $slot->start->toDateString())
                ->whereHas('bookingResources', fn ($query) => $query->where('resource_id', $resource->id))
                ->with('service:id,buffer_minutes')
                ->get(['id', 'starts_at', 'ends_at', 'service_id']);
        } else {
            // Servicio sin recurso propio: la disponibilidad se comparte por
            // service+location entre las reservas que tampoco tienen recurso.
            $conflicting = Booking::query()
                ->whereIn('status', $activeStatuses)
                ->where('service_id', $service->id)
                ->where('location_id', $location->id)
                ->whereDate('starts_at', $slot->start->toDateString())
                ->whereDoesntHave('bookingResources')
                ->with('service:id,buffer_minutes')
                ->get(['id', 'starts_at', 'ends_at', 'service_id']);
        }

        $overlappingCount = $conflicting->filter(function (Booking $booking) use ($slot) {
            $bufferMinutes = $booking->service->buffer_minutes ?? 0;
            $occupied = (new TimeRange($booking->starts_at, $booking->ends_at))->withTrailingBuffer($bufferMinutes);

            return $occupied->overlaps($slot);
        })->count();

        return $overlappingCount < $service->capacity_per_slot;
    }
}
