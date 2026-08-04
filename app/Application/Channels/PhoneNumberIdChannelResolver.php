<?php

namespace App\Application\Channels;

use App\Application\Contracts\ChannelResolverInterface;
use App\Domain\Tenancy\Channel;

/**
 * Única implementación necesaria hoy: phone_number_id ya es único por
 * diseño (Hito 1) — un lookup directo alcanza. El día que haya más de un
 * proveedor con formatos de identificador distintos, esto sigue siendo
 * válido porque Channel::phone_number_id ya es el campo normalizado
 * (Parte XVI), no algo específico de Meta.
 */
class PhoneNumberIdChannelResolver implements ChannelResolverInterface
{
    public function resolve(string $phoneNumberId): ?Channel
    {
        return Channel::where('phone_number_id', $phoneNumberId)->first();
    }
}
