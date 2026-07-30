<?php

namespace App\Enums;

/**
 * Etapa de venta del Lead, manejada por el asesor desde el panel.
 * Independiente del campo `status` de Lead (pendiente/aceptado/cerrado/
 * cancelado), que es el que dispara mensajes automáticos reales al cliente
 * por WhatsApp — este campo es solo para seguimiento interno del asesor.
 */
enum LeadPipelineStage: string
{
    case NUEVO               = 'NUEVO';
    case CONTACTADO          = 'CONTACTADO';
    case COTIZACION_ENVIADA  = 'COTIZACION_ENVIADA';
    case NEGOCIANDO          = 'NEGOCIANDO';
    case VENTA_REALIZADA     = 'VENTA_REALIZADA';
    case VENTA_PERDIDA       = 'VENTA_PERDIDA';

    public function label(): string
    {
        return match ($this) {
            self::NUEVO              => '🟢 Nuevo',
            self::CONTACTADO         => '🟡 Contactado',
            self::COTIZACION_ENVIADA => '🔵 Cotización enviada',
            self::NEGOCIANDO         => '🟠 Negociando',
            self::VENTA_REALIZADA    => '✅ Venta realizada',
            self::VENTA_PERDIDA      => '🔴 Venta perdida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NUEVO              => 'success',
            self::CONTACTADO         => 'warning',
            self::COTIZACION_ENVIADA => 'info',
            self::NEGOCIANDO         => 'warning',
            self::VENTA_REALIZADA    => 'success',
            self::VENTA_PERDIDA      => 'danger',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
