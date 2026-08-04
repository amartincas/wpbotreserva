<?php

namespace App\Application\Notifications;

use App\Application\Exceptions\NotificationDeliveryException;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de contacto con la Graph API de Meta — deliberadamente sin
 * conocer `Channel`, `Organization` ni ningún modelo del dominio: solo
 * primitivos (IDs, tokens, texto). Si Meta cambia versión de API o forma
 * de payload, este es el único archivo que cambia; WhatsAppNotificationSender
 * (quién decide cuándo/qué notificar) ni se entera.
 */
class MetaWhatsAppClient
{
    private const API_VERSION = 'v21.0';

    public function sendTextMessage(string $phoneNumberId, string $accessToken, string $toPhoneE164, string $message): void
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post(sprintf('https://graph.facebook.com/%s/%s/messages', self::API_VERSION, $phoneNumberId), [
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
