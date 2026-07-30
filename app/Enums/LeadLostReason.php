<?php

namespace App\Enums;

enum LeadLostReason: string
{
    case PRECIO                = 'PRECIO';
    case CAMBIO_FECHAS         = 'CAMBIO_FECHAS';
    case SIN_PRESUPUESTO       = 'SIN_PRESUPUESTO';
    case NO_RESPONDIO          = 'NO_RESPONDIO';
    case COMPRO_OTRA_AGENCIA   = 'COMPRO_OTRA_AGENCIA';
    case PERDIO_INTERES        = 'PERDIO_INTERES';
    case NO_TENIA_PASAPORTE    = 'NO_TENIA_PASAPORTE';
    case OTRO                  = 'OTRO';

    public function label(): string
    {
        return match ($this) {
            self::PRECIO              => 'Precio',
            self::CAMBIO_FECHAS       => 'Cambió fechas',
            self::SIN_PRESUPUESTO     => 'Sin presupuesto',
            self::NO_RESPONDIO        => 'No respondió',
            self::COMPRO_OTRA_AGENCIA => 'Compró con otra agencia',
            self::PERDIO_INTERES      => 'Perdió interés',
            self::NO_TENIA_PASAPORTE  => 'No tenía pasaporte',
            self::OTRO                => 'Otro',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
