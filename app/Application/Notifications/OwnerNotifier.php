<?php

namespace App\Application\Notifications;

use App\Application\Channels\CentralChannelProvider;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\OwnerNotifierInterface;
use App\Application\Exceptions\CentralChannelUnavailableException;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Organization;

/**
 * B6 — implementación de OwnerNotifierInterface: Organization.owner_phone
 * como destinatario, el Channel CENTRAL activo (CentralChannelProvider)
 * como remitente. Nunca usa el Channel BUSINESS de la Organization, ni un
 * teléfono de Resource.
 *
 * Todo fallo se informa como NotificationDeliveryException — la misma que
 * ya manejan los llamadores (los listeners la dejan propagar para que la
 * cola reintente; ReviewPastBookings la captura y reintenta en la próxima
 * corrida), así que no hace falta que conozcan los motivos puntuales.
 */
class OwnerNotifier implements OwnerNotifierInterface
{
    public function __construct(
        private readonly CentralChannelProvider $central,
        private readonly ChannelClientInterface $client,
    ) {}

    public function sendTemplate(Organization $organization, string $templateName, string $language, array $bodyParameters): void
    {
        if ($organization->owner_phone === null) {
            throw new NotificationDeliveryException("La organización #{$organization->id} no tiene owner_phone.");
        }

        try {
            $channel = $this->central->active();
        } catch (CentralChannelUnavailableException $e) {
            throw new NotificationDeliveryException($e->getMessage(), previous: $e);
        }

        $this->client->sendTemplateMessage($channel, $organization->owner_phone, $templateName, $language, $bodyParameters);
    }
}
