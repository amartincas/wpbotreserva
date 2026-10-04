<?php

namespace App\Application\Notifications;

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Channel;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de contacto con la Graph API de Meta. Sabe qué campos de
 * Channel::credentials le corresponden a este proveedor (access_token) —
 * esa es justamente la responsabilidad que ChannelClientInterface delega en
 * cada implementación concreta, no en quien la invoca.
 *
 * Post-E2E Fase 1 (Hallazgo 5): al ser el único punto de salida real hacia
 * Meta (los 7 Agents y WhatsAppNotificationSender convergen acá), es
 * también el único lugar donde hace falta registrar mensajes salientes en
 * conversation_messages — sin tocar ningún Agent individualmente. Nunca se
 * completa organization_id acá: ChannelClientInterface solo recibe Channel,
 * no la Organization ya resuelta por el Router/Agent que lo invoca —
 * resolverla de nuevo acá solo para completar este campo de observabilidad
 * sería una consulta extra fuera de lugar en el único punto de salida real
 * hacia Meta, así que queda NULL a propósito en todas las filas outbound.
 */
class MetaWhatsAppClient implements ChannelClientInterface
{
    public const API_VERSION = 'v21.0';

    public function sendTextMessage(Channel $channel, string $to, string $message): void
    {
        $accessToken = $this->accessTokenFor($channel);

        $this->post($channel, $accessToken, $to, [
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void
    {
        $accessToken = $this->accessTokenFor($channel);

        $this->post($channel, $accessToken, $to, [
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $value) => ['type' => 'text', 'text' => $value],
                            $bodyParameters
                        ),
                    ],
                ],
            ],
        ]);
    }

    public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
    {
        $accessToken = $this->accessTokenFor($channel);

        $this->post($channel, $accessToken, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => array_map(
                        fn (array $button) => [
                            'type' => 'reply',
                            'reply' => ['id' => $button['id'], 'title' => $button['title']],
                        ],
                        $buttons
                    ),
                ],
            ],
        ]);
    }

    private function accessTokenFor(Channel $channel): string
    {
        $accessToken = $channel->credentials['access_token'] ?? null;

        if (! $accessToken || ! $channel->phone_number_id) {
            throw new NotificationDeliveryException(
                "El canal #{$channel->id} no tiene credenciales completas para Meta Cloud API."
            );
        }

        return $accessToken;
    }

    private function post(Channel $channel, string $accessToken, string $to, array $payload): void
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post(sprintf('https://graph.facebook.com/%s/%s/messages', self::API_VERSION, $channel->phone_number_id), array_merge([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($to, '+'),
            ], $payload));

        if ($response->failed()) {
            throw new NotificationDeliveryException(
                "Meta API respondió {$response->status()} al notificar a {$to}: {$response->body()}"
            );
        }

        ConversationMessage::create([
            'channel_id' => $channel->id,
            'organization_id' => null,
            'customer_phone' => $to,
            'direction' => 'outbound',
            'message_id' => $response->json('messages.0.id'),
            'body' => $this->describeOutboundBody($payload),
            'created_at' => now(),
        ]);
    }

    /**
     * Reconstruye un texto de auditoría legible a partir del payload real
     * enviado a Meta — nunca credenciales, tokens ni headers, solo el
     * contenido conversacional (Hallazgo 5, sección "privacidad").
     */
    private function describeOutboundBody(array $payload): string
    {
        return match ($payload['type'] ?? null) {
            'text' => $payload['text']['body'] ?? '',
            'template' => sprintf(
                '[plantilla: %s] %s',
                $payload['template']['name'] ?? '',
                implode(', ', array_column($payload['template']['components'][0]['parameters'] ?? [], 'text')),
            ),
            'interactive' => $payload['interactive']['body']['text'] ?? '',
            default => '',
        };
    }
}
