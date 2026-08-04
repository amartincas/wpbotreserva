<?php

namespace App\Application\Notifications;

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

/**
 * Implementación real para el MVP — resuelve el Channel de WhatsApp de la
 * organización (Parte XVI) y valida que esté en condiciones de recibir un
 * envío. La comunicación con Meta en sí está encapsulada en
 * MetaWhatsAppClient — esta clase no arma payloads ni conoce la Graph API.
 *
 * Nunca usa el `Store`/`WhatsAppPlatformSetting` del bot de turismo — esa
 * dependencia habría acoplado el dominio nuevo al viejo justo donde más
 * cuidado pusimos en desacoplarlos.
 *
 * No usa ChannelResolver (Hito 4) — eso resuelve a qué Organization
 * pertenece un mensaje ENTRANTE. Acá el caller ya tiene la Organization
 * (viene de una Booking ya creada); solo hace falta su canal de salida.
 */
class WhatsAppNotificationSender implements NotificationSenderInterface
{
    public function __construct(private readonly MetaWhatsAppClient $client) {}

    public function send(Organization $organization, string $toPhoneE164, string $message): void
    {
        $channel = $organization->channels()
            ->where('channel_type', ChannelType::WHATSAPP->value)
            ->first();

        if (! $channel) {
            throw new NotificationDeliveryException(
                "La organización #{$organization->id} no tiene un canal de WhatsApp vinculado."
            );
        }

        if ($channel->status !== ChannelStatus::ACTIVE) {
            throw new NotificationDeliveryException(
                "El canal #{$channel->id} de la organización #{$organization->id} no está activo (estado: {$channel->status->value})."
            );
        }

        $accessToken = $channel->credentials['access_token'] ?? null;

        if (! $accessToken || ! $channel->phone_number_id) {
            throw new NotificationDeliveryException("El canal #{$channel->id} no tiene credenciales completas.");
        }

        $this->client->sendTextMessage($channel->phone_number_id, $accessToken, $toPhoneE164, $message);
    }
}
