<?php

namespace App\Application\Channels;

use App\Application\Exceptions\CentralChannelUnavailableException;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;

/**
 * Resuelve el número CENTRAL de WpbotReserva — el único Channel por el que
 * se le habla al owner (onboarding, administración, alertas). A diferencia
 * de ChannelResolver (que identifica el Channel de un mensaje ENTRANTE por
 * su phone_number_id), acá no hay mensaje de por medio: se usa para envíos
 * iniciados por el sistema (B6).
 */
class CentralChannelProvider
{
    public function active(): Channel
    {
        $channels = Channel::query()
            ->where('role', ChannelRole::CENTRAL->value)
            ->where('status', ChannelStatus::ACTIVE->value)
            ->limit(2)
            ->get();

        if ($channels->isEmpty()) {
            throw new CentralChannelUnavailableException('No hay ningún Channel CENTRAL activo.');
        }

        if ($channels->count() > 1) {
            throw new CentralChannelUnavailableException(
                'Hay más de un Channel CENTRAL activo (#'.$channels->pluck('id')->implode(', #').') — estado inconsistente.'
            );
        }

        return $channels->first();
    }
}
