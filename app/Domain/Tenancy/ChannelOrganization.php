<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Domain\Tenancy\Exceptions\CentralChannelLinkException;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot channel_organization con las invariantes de la separación
 * CENTRAL / BUSINESS. Channel::organizations() y Organization::channels()
 * lo declaran vía using(), así que attach()/sync()/syncWithoutDetaching()
 * pasan siempre por save() de esta clase (Laravel crea cada fila con el
 * pivot personalizado) y el chequeo de `creating` corre antes del INSERT:
 *
 *  - un Channel CENTRAL nunca se vincula a una Organization;
 *  - una Organization tiene como máximo un Channel (siempre BUSINESS, por
 *    la regla anterior).
 *
 * UNIQUE(channel_id) — Channel → 0 o 1 Organization, Fase 6 — sigue
 * viviendo en la base de datos, no acá: es lo que detecta la carrera real
 * entre dos registros concurrentes (ver RegisterOrganizationCommand).
 *
 * Un INSERT crudo (DB::table) saltea este chequeo — por eso el Router
 * también rechaza un CENTRAL vinculado al recibir un mensaje (B4).
 */
class ChannelOrganization extends Pivot
{
    protected $table = 'channel_organization';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (self $pivot) {
            $channel = Channel::find($pivot->channel_id);

            if ($channel?->isCentral()) {
                throw new CentralChannelLinkException(
                    "El Channel #{$channel->id} es CENTRAL y no puede vincularse a una Organization."
                );
            }

            $hasAnotherChannel = static::query()
                ->where('organization_id', $pivot->organization_id)
                ->where('channel_id', '!=', $pivot->channel_id)
                ->exists();

            if ($hasAnotherChannel) {
                throw new BusinessChannelAlreadyConnectedException(
                    "La Organization #{$pivot->organization_id} ya tiene un Channel BUSINESS vinculado."
                );
            }
        });
    }
}
