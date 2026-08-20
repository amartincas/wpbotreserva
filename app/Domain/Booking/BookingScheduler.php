<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
use App\Domain\Booking\Exceptions\InvalidBookingRequestException;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\Booking\ValueObjects\AvailableSlot;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de entrada para crear una reserva (Parte III/XI punto 3).
 * Alcance fijo a propósito: valida disponibilidad, crea el aggregate,
 * asigna el/los recurso(s), dispara el evento. Nada de permisos,
 * entitlements, reglas comerciales ni depósitos acá — eso vive en la capa
 * de Application (Hito 3) o en Payments — para que esta clase nunca se
 * convierta en un God Service.
 *
 * Concurrencia (Parte XI punto 1): la invariante de no-solapamiento se hace
 * cumplir con un `SELECT ... FOR UPDATE` sobre el/los Resource candidato(s)
 * (o sobre el propio Service cuando no hace falta recurso), seguido de una
 * revalidación de disponibilidad DENTRO de la transacción — es el único
 * punto de verdad real, todo lo anterior a esto es solo una selección
 * optimista de candidato.
 */
class BookingScheduler implements BookingSchedulerInterface
{
    public function __construct(private readonly AvailabilityCalculatorInterface $availability) {}

    public function schedule(
        Service $service,
        Location $location,
        Customer $customer,
        CarbonImmutable $startsAt,
        ?Resource $resource = null,
        ?string $notes = null,
    ): Booking {
        if ($resource && ! $this->resourceCanPerformService($resource, $service)) {
            throw new InvalidBookingRequestException(
                "El recurso #{$resource->id} no puede prestar el servicio «{$service->name}»."
            );
        }

        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        $needsResource = $service->resourceRequirements()->exists();

        return DB::transaction(function () use ($service, $location, $customer, $startsAt, $endsAt, $resource, $notes, $needsResource) {
            $lockedResource = null;

            if ($needsResource) {
                $lockedResource = $resource
                    ? $this->lockSpecificResourceIfAvailable($resource, $service, $location, $startsAt, $endsAt)
                    : $this->lockFirstAvailableResource($service, $location, $startsAt, $endsAt);

                if (! $lockedResource) {
                    throw new SlotNoLongerAvailableException('Ese horario ya no está disponible.');
                }
            } else {
                // Sin recurso propio: serializa los intentos concurrentes
                // contra el Service mismo (Parte XI punto 1).
                Service::whereKey($service->id)->lockForUpdate()->first();

                if (! $this->isStillAvailable($service, $location, $startsAt, $endsAt, null)) {
                    throw new SlotNoLongerAvailableException('Ese horario ya no está disponible.');
                }
            }

            $booking = Booking::create([
                'organization_id' => $location->organization_id,
                'location_id' => $location->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_minutes' => $service->duration_minutes,
                'status' => BookingStatus::CONFIRMED,
                'notes' => $notes,
                // Snapshot al confirmar (Parte II R6 / Parte XI punto 4) —
                // un cambio posterior en Service no altera esta reserva.
                'cancellation_policy_snapshot' => $service->cancellation_policy,
            ]);

            if ($lockedResource) {
                BookingResource::create([
                    'booking_id' => $booking->id,
                    'resource_id' => $lockedResource->id,
                ]);
            }

            BookingConfirmed::dispatch($booking->fresh(['bookingResources']));

            return $booking;
        });
    }

    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        if ($booking->isTerminal()) {
            throw new BookingAlreadyTerminalException(
                "La reserva #{$booking->id} ya está en un estado terminal ({$booking->status->value})."
            );
        }

        $booking->update([
            'status' => BookingStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        BookingCancelled::dispatch($booking->fresh(['bookingResources']));

        return $booking;
    }

    public function reschedule(Booking $booking, CarbonImmutable $newStartsAt): Booking
    {
        if ($booking->isTerminal()) {
            throw new BookingAlreadyTerminalException(
                "La reserva #{$booking->id} ya está en un estado terminal ({$booking->status->value})."
            );
        }

        $service = $booking->service;
        $location = $booking->location;
        $newEndsAt = $newStartsAt->addMinutes($service->duration_minutes);
        $resource = $booking->bookingResources()->first()?->resource;

        return DB::transaction(function () use ($booking, $service, $location, $newStartsAt, $newEndsAt, $resource) {
            $previousStartsAt = CarbonImmutable::instance($booking->starts_at);

            if ($resource) {
                $lockedResource = $this->lockSpecificResourceIfAvailable($resource, $service, $location, $newStartsAt, $newEndsAt);

                if (! $lockedResource) {
                    throw new SlotNoLongerAvailableException('Ese horario ya no está disponible.');
                }
            } else {
                // Mismo criterio que schedule(): sin recurso propio, serializa
                // contra el Service mismo (Parte XI punto 1).
                Service::whereKey($service->id)->lockForUpdate()->first();

                if (! $this->isStillAvailable($service, $location, $newStartsAt, $newEndsAt, null)) {
                    throw new SlotNoLongerAvailableException('Ese horario ya no está disponible.');
                }
            }

            $booking->update(['starts_at' => $newStartsAt, 'ends_at' => $newEndsAt]);

            BookingRescheduled::dispatch($booking->fresh(['bookingResources']), $previousStartsAt);

            return $booking;
        });
    }

    public function confirm(Booking $booking): Booking
    {
        if ($booking->isTerminal()) {
            throw new BookingAlreadyTerminalException(
                "La reserva #{$booking->id} ya está en un estado terminal ({$booking->status->value})."
            );
        }

        if ($booking->status === BookingStatus::PENDING) {
            $booking->update(['status' => BookingStatus::CONFIRMED]);
            BookingConfirmed::dispatch($booking->fresh(['bookingResources']));
        }

        return $booking;
    }

    public function complete(Booking $booking): Booking
    {
        if ($booking->isTerminal()) {
            throw new BookingAlreadyTerminalException(
                "La reserva #{$booking->id} ya está en un estado terminal ({$booking->status->value})."
            );
        }

        $booking->update(['status' => BookingStatus::COMPLETED]);

        return $booking;
    }

    public function markNoShow(Booking $booking): Booking
    {
        $allowedOrigins = [BookingStatus::CONFIRMED, BookingStatus::COMPLETED];

        if (! in_array($booking->status, $allowedOrigins, true)) {
            throw new BookingAlreadyTerminalException(
                "La reserva #{$booking->id} ya está en un estado terminal ({$booking->status->value})."
            );
        }

        $booking->update(['status' => BookingStatus::NO_SHOW]);

        return $booking;
    }

    private function resourceCanPerformService(Resource $resource, Service $service): bool
    {
        return $service->resources()->where('resources.id', $resource->id)->exists();
    }

    /**
     * Bloquea el recurso pedido explícitamente por el caller y revalida —
     * sin fallback a otro recurso, porque el caller pidió ESE en particular.
     */
    private function lockSpecificResourceIfAvailable(
        Resource $resource,
        Service $service,
        Location $location,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): ?Resource {
        $locked = Resource::whereKey($resource->id)->lockForUpdate()->first();

        return $this->isStillAvailable($service, $location, $startsAt, $endsAt, $locked) ? $locked : null;
    }

    /**
     * Sin recurso explícito: prueba los candidatos plausibles en orden,
     * bloqueando y revalidando cada uno, hasta encontrar uno que siga libre
     * bajo lock. Evita el bug de "rechazar la reserva aunque otro recurso
     * distinto sí esté libre" que tendría elegir solo el primer candidato
     * de una consulta optimista sin lock.
     */
    private function lockFirstAvailableResource(
        Service $service,
        Location $location,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): ?Resource {
        $candidates = $this->availability
            ->availableSlots($service, $location, $startsAt->startOfDay())
            ->filter(fn (AvailableSlot $slot) => $slot->range->start->equalTo($startsAt) && $slot->range->end->equalTo($endsAt))
            ->pluck('resource')
            ->filter()
            ->unique('id');

        foreach ($candidates as $candidate) {
            $locked = Resource::whereKey($candidate->id)->lockForUpdate()->first();

            if ($this->isStillAvailable($service, $location, $startsAt, $endsAt, $locked)) {
                return $locked;
            }

            // No se libera el lock a mano: sigue vivo hasta el commit/rollback
            // de la transacción externa mientras se prueba el próximo candidato.
        }

        return null;
    }

    private function isStillAvailable(
        Service $service,
        Location $location,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?Resource $resource,
    ): bool {
        return $this->availability
            ->availableSlots($service, $location, $startsAt->startOfDay(), $resource)
            ->contains(fn (AvailableSlot $slot) => $slot->range->start->equalTo($startsAt) && $slot->range->end->equalTo($endsAt));
    }
}
