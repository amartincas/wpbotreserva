<?php

namespace App\Application\Tenancy;

use App\Domain\Tenancy\Organization;

/**
 * B9 — lo que carga el super-admin desde Filament para conectar el
 * WhatsApp propio de una Organization. El access token llega solo por acá
 * (nunca por WhatsApp) y se guarda cifrado en Channel::credentials.
 */
final class ConnectBusinessChannelData
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $phoneNumber,
        public readonly string $phoneNumberId,
        public readonly string $businessAccountId,
        public readonly string $accessToken,
    ) {}
}
