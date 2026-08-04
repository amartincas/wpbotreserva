<?php

namespace App\Application\Notifications;

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\Http;

/**
 * Implementación real para el MVP — resuelve el Channel de WhatsApp de la
 * organización (Parte XVI) y llama directo a la Graph API de Meta. Nunca
 * usa el `Store`/`WhatsAppPlatformSetting` del bot de turismo — esa
 * dependencia habría acoplado el dominio nuevo al viejo justo donde más
 * cuidado pusimos en desacoplarlos.
 *
 * No usa ChannelResolver (Hito 4) — eso resuelve a qué Organization
 * pertenece un mensaje ENTRANTE. Acá el caller ya tiene la Organization
 * (viene de una Booking ya creada); solo hace falta su canal de salida.
 */
class WhatsAppNotificationSender implements NotificationSenderInterface
{
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

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post("https://graph.facebook.com/v21.0/{$channel->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($toPhoneE164, '+'),
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if ($response->failed()) {
            throw new NotificationDeliveryException(
                "Meta API respondió {$response->status()} al notificar a {$toPhoneE164}: {$response->body()}"
            );
        }
    }
}
