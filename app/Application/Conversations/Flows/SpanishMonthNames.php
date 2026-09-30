<?php

namespace App\Application\Conversations\Flows;

/**
 * Mapa de nombres de mes en español → número de mes (1-12). Mismo criterio
 * que SpanishWeekdayNames: reconocimiento determinista, sin pasar por IA.
 * Lista de nombres igual a la ya usada por
 * DateFieldExtractor::isAmbiguousDayWithoutMonth() (incluye la variante
 * "setiembre").
 */
final class SpanishMonthNames
{
    public const NAMES = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    public static function indexOf(string $name): ?int
    {
        return self::NAMES[$name] ?? null;
    }
}
