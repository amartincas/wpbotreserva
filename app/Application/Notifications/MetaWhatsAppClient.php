<?php

namespace App\Application\Notifications;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Channel;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de contacto con la Graph API de Meta. Sabe qué campos de
 * Channel::credentials le corresponden a este proveedor (access_token) —
 * esa es justamente la responsabilidad que ChannelClientInterface delega en
 * cada implementación concreta, no en quien la invoca.
 */
class MetaWhatsAppClient implements ChannelClientInterface
{
    private const API_VERSION = 'v21.0';

    public function sendTextMessage(Channel $channel, string $to, string $message): void
    {
        $accessToken = $channel->credentials['access_token'] ?? null;

        if (! $accessToken || ! $channel->phone_number_id) {
            throw new NotificationDeliveryException(
                "El canal #{$channel->id} no tiene credenciales completas para Meta Cloud API."
            );
        }

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post(sprintf('https://graph.facebook.com/%s/%s/messages', self::API_VERSION, $channel->phone_number_id), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($to, '+'),
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if ($response->failed()) {
            throw new NotificationDeliveryException(
                "Meta API respondió {$response->status()} al notificar a {$to}: {$response->body()}"
            );
        }
    }
}
