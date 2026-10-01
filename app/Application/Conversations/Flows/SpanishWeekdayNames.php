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
        // Formas completas — lunes/martes/miércoles/jueves/viernes son
        // invariantes singular=plural en español, no necesitan entrada
        // extra para eso (Fase 7). sábado/domingo sí difieren del plural,
        // así que esos dos llevan una entrada adicional cada uno.
        'domingo' => 0,
        'domingos' => 0,
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'miércoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
        'sábado' => 6,
        'sabados' => 6,
        'sábados' => 6,
        // Abreviaturas (Fase 7), con y sin tilde donde aplica.
        'dom' => 0,
        'lun' => 1,
        'mar' => 2,
        'mie' => 3,
        'mié' => 3,
        'jue' => 4,
        'vie' => 5,
        'sab' => 6,
        'sáb' => 6,
    ];

    /**
     * Orden circular lunes..domingo — sirve para expandir rangos de días
     * ("Lunes a Viernes", o el caso menos común "Viernes a Domingo", que
     * cruza el corte domingo=0/lunes=1 de la convención de arriba).
     */
    public const WEEK_ORDER = [1, 2, 3, 4, 5, 6, 0];

    private const DISPLAY_NAMES = [
        0 => 'domingo',
        1 => 'lunes',
        2 => 'martes',
        3 => 'miércoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sábado',
    ];

    public static function indexOf(string $name): ?int
    {
        return self::NAMES[$name] ?? null;
    }

    public static function nameOf(int $index): ?string
    {
        return self::DISPLAY_NAMES[$index] ?? null;
    }
}
