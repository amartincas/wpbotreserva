<?php

namespace App\Application\Contracts;

use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Organization;

/**
 * Parte IX punto 4 — los listeners de Domain Events dependen de esta
 * interfaz, nunca de un servicio de canal concreto directamente. Agregar
 * Telegram/SMS mañana es una implementación nueva, no un cambio acá ni en
 * quien la consume.
 */
interface NotificationSenderInterface
{
    /**
     * @throws NotificationDeliveryException si el envío falla
     */
    public function send(Organization $organization, string $toPhoneE164, string $message): void;
}
