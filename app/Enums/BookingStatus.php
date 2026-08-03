<?php

namespace App\Enums;

/**
 * Flujo único sembrado del Incremento 1 (Parte XII — se cortaron las tablas
 * de workflow configurable; encapsulado detrás de Booking::status() para
 * que migrar a un workflow por tabla más adelante no toque call sites).
 */
enum BookingStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
    case COMPLETED = 'COMPLETED';
    case NO_SHOW = 'NO_SHOW';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CANCELLED, self::COMPLETED, self::NO_SHOW => true,
            self::PENDING, self::CONFIRMED => false,
        };
    }
}
