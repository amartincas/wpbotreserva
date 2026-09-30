<?php

namespace App\Application\Booking\Agenda;

use Carbon\CarbonImmutable;

/**
 * Resultado de AgendaDateResolver::resolve() — 3 estados explícitos (mismo
 * espíritu que FieldExtractionResult): una referencia de fecha con FORMA
 * reconocida puede seguir siendo calendáricamente inválida (ej. 31/02), y
 * eso es un caso distinto de no reconocer ninguna forma de fecha en el
 * texto — ver diseño Fase 4, ajuste de "fecha inválida vs no reconocida".
 */
final class AgendaDateResolution
{
    private function __construct(
        public readonly ?CarbonImmutable $date,
        public readonly bool $invalidCalendarDate,
        public readonly bool $unrecognized,
    ) {}

    public static function success(CarbonImmutable $date): self
    {
        return new self($date, false, false);
    }

    public static function invalidCalendarDate(): self
    {
        return new self(null, true, false);
    }

    public static function unrecognized(): self
    {
        return new self(null, false, true);
    }
}
