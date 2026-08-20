<?php

namespace App\Application\Contracts;

use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Channel;

/**
 * Abstrae "cómo hablar con el proveedor de este canal" — MetaWhatsAppClient
 * es la única implementación hoy. El día que exista un Channel de Telegram
 * o Instagram, se agrega su propio cliente (TelegramClient, etc.)
 * implementando esto — WhatsAppNotificationSender (o el sender análogo de
 * ese canal) no cambia una línea.
 *
 * Recibe el Channel completo (no primitivos sueltos) porque cada proveedor
 * necesita campos distintos de sus credentials (access_token para Meta,
 * bot_token para Telegram, etc.) — es cada implementación la que sabe qué
 * extraer, no quien la invoca.
 */
interface ChannelClientInterface
{
    /**
     * @throws NotificationDeliveryException si el envío falla o el canal no tiene credenciales válidas para este proveedor
     */
    public function sendTextMessage(Channel $channel, string $to, string $message): void;

    /**
     * Mensaje de plantilla (HSM) — único tipo de envío permitido fuera de la
     * ventana de 24h de conversación gratuita de WhatsApp (Incremento 3:
     * recordatorio de turno, que por diseño casi siempre se manda fuera de
     * esa ventana). $bodyParameters va posicional, en el mismo orden que
     * las variables {{1}}, {{2}}... de la plantilla ya aprobada por Meta.
     *
     * @param  string[]  $bodyParameters
     *
     * @throws NotificationDeliveryException si el envío falla, el canal no tiene credenciales válidas, o la plantilla no está aprobada
     */
    public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void;
}
