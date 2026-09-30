<?php

namespace App\Domain\Booking\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Intervalo semiabierto [inicio, siguiente) de un día de calendario,
 * normalizado a config('app.timezone') para comparar contra una columna
 * DATETIME (ej. bookings.starts_at) — corrección transversal de timezone.
 *
 * Deliberadamente NO whereBetween(): ese operador es inclusivo en ambos
 * extremos, así que una reserva exactamente a las 00:00:00 del día
 * siguiente quedaría incluida en el día anterior. end es siempre exclusivo.
 *
 * Distinto de TimeRange (un rango horario DENTRO de un día, para ventanas
 * de horario) — esto es el día completo, como instante absoluto, para
 * consultas de agenda/disponibilidad.
 */
final class CalendarDayRange
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * $date puede traer el timezone de una Organization (ej. resuelto por
     * AgendaDateResolver/DateFieldExtractor) — nunca se usa directamente
     * para comparar contra la base, siempre se normaliza acá primero.
     */
    public static function forDate(CarbonImmutable $date): self
    {
        $localStart = $date->startOfDay();

        return new self(
            $localStart->setTimezone(config('app.timezone')),
            $localStart->addDay()->setTimezone(config('app.timezone')),
        );
    }
}
