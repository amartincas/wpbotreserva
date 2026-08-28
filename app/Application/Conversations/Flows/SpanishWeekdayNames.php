<?php

namespace App\Application\Conversations\Flows;

/**
 * Mapa de nombres de día en español → índice 0=domingo..6=sábado (mismo
 * convenio que resource_schedules.weekday, documentado también en
 * WeeklyScheduleFieldExtractor). Compartido entre DateFieldExtractor y
 * WeeklyScheduleFieldExtractor — ambos necesitan reconocer nombres de día de
 * forma determinista, antes de llamar a la IA, y hasta ahora cada uno tenía
 * su propia copia del mismo mapa.
 */
final class SpanishWeekdayNames
{
    public const NAMES = [
        'domingo' => 0,
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'miércoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
        'sábado' => 6,
    ];

    /**
     * Orden circular lunes..domingo — sirve para expandir rangos de días
     * ("Lunes a Viernes", o el caso menos común "Viernes a Domingo", que
     * cruza el corte domingo=0/lunes=1 de la convención de arriba).
     */
    public const WEEK_ORDER = [1, 2, 3, 4, 5, 6, 0];

    public static function indexOf(string $name): ?int
    {
        return self::NAMES[$name] ?? null;
    }
}
