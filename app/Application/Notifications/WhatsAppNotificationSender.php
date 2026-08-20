<?php

namespace App\Application\Notifications;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

/**
 * Implementación real para el MVP — resuelve el Channel de WhatsApp de la
 * organización (Parte XVI) y valida que esté en condiciones genéricas de
 * recibir un envío (existe, está activo). Depende de ChannelClientInterface,
 * no de MetaWhatsAppClient concreto: el día que exista otro proveedor de
 * WhatsApp (360dialog, Twilio) o incluso otro canal (Telegram, con su
 * propio sender), esta clase no cambia — solo el binding en el contenedor.
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
    public function __construct(private readonly ChannelClientInterface $client) {}

    public function send(Organization $organization, string $toPhoneE164, string $message): void
    {
        $channel = $this->activeWhatsAppChannelFor($organization);

        $this->client->sendTextMessage($channel, $toPhoneE164, $message);
    }

    public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void
    {
        $channel = $this->activeWhatsAppChannelFor($organization);

        $this->client->sendTemplateMessage($channel, $toPhoneE164, $templateName, $language, $bodyParameters);
    }

    private function activeWhatsAppChannelFor(Organization $organization): Channel
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

        return $channel;
    }
}
