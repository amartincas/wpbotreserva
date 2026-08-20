<?php

namespace App\Domain\Booking\Contracts;

use App\Domain\Booking\Booking;
use App\Domain\Booking\Exceptions\BookingAlreadyTerminalException;
use App\Domain\Booking\Exceptions\InvalidBookingRequestException;
use App\Domain\Booking\Exceptions\SlotNoLongerAvailableException;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use Carbon\CarbonImmutable;

/**
 * Único punto de entrada permitido para crear, cancelar o reprogramar una
 * reserva (Parte III/XI punto 3) — nadie más inserta/actualiza en
 * `bookings` directamente. Permisos/entitlements/depósitos viven en la capa
 * de Application (Hito 3) o en Payments, nunca acá (Parte XI punto 3 — la
 * regla que evita que esto se vuelva un God Service).
 */
interface BookingSchedulerInterface
{
    /**
     * @throws InvalidBookingRequestException si el recurso no puede prestar el servicio
     * @throws SlotNoLongerAvailableException si, revalidado bajo lock, el horario ya no está libre
     */
    public function schedule(
        Service $service,
        Location $location,
        Customer $customer,
        CarbonImmutable $startsAt,
        ?Resource $resource = null,
        ?string $notes = null,
    ): Booking;

    /**
     * @throws BookingAlreadyTerminalException si la reserva ya está en un estado terminal
     */
    public function cancel(Booking $booking, ?string $reason = null): Booking;

    /**
     * @throws BookingAlreadyTerminalException si la reserva ya está en un estado terminal
     * @throws SlotNoLongerAvailableException si, revalidado bajo lock, el nuevo horario no está libre
     */
    public function reschedule(Booking $booking, CarbonImmutable $newStartsAt): Booking;

    /**
     * Transición PENDING → CONFIRMED explícita (comando admin `confirmar
     * <id>`, Incremento 2). Idempotente si ya está CONFIRMED — no vuelve a
     * disparar BookingConfirmed en ese caso, solo cuando hay una transición
     * real (evita notificar al cliente dos veces por error del dueño).
     *
     * @throws BookingAlreadyTerminalException si la reserva ya está en un estado terminal
     */
    public function confirm(Booking $booking): Booking;

    /**
     * Transición CONFIRMED → COMPLETED (respaldo automático de
     * bookings:review-past a los 7 días sin resolución, Incremento 2) — sin
     * esto, una reserva cuya fecha ya pasó queda CONFIRMED para siempre y
     * GestionReservaAgent la sigue ofreciendo como "activa" indefinidamente.
     * Sin evento propio a propósito: no hay ningún consumidor real hoy (no
     * se notifica al cliente que su turno ya pasó) — se agrega el día que
     * aparezca uno.
     *
     * @throws BookingAlreadyTerminalException si la reserva ya está en un estado terminal
     */
    public function complete(Booking $booking): Booking;

    /**
     * Transición a NO_SHOW (comando admin `ausente <id>`, Incremento 2).
     * Excepción deliberada a la regla general de Parte II R4 ("ningún
     * estado terminal tiene transiciones salientes"): además de CONFIRMED,
     * también acepta como origen una reserva ya COMPLETED — es lo que le
     * permite al dueño corregir un auto-completado del respaldo de 7 días
     * cuando en realidad el cliente nunca llegó. Nunca acepta CANCELLED
     * como origen (cancelar y no-show son hechos distintos, uno no
     * reemplaza al otro).
     *
     * @throws BookingAlreadyTerminalException si la reserva está CANCELLED o ya es NO_SHOW
     */
    public function markNoShow(Booking $booking): Booking;
}
