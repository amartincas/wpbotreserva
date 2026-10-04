<?php

namespace App\Application\Contracts;

use App\Application\Exceptions\NotificationDeliveryException;
use App\Domain\Tenancy\Organization;

/**
 * B6 — avisos iniciados por el sistema hacia el DUEÑO de una Organization
 * (nueva reserva, cancelación, reprogramación, turnos vencidos). El
 * destinatario es siempre Organization.owner_phone y el número que envía es
 * siempre el CENTRAL: es por donde el owner administra su negocio, y donde
 * contesta comandos como "ausente <id>".
 *
 * Contraparte de NotificationSenderInterface, que habla con los CLIENTES de
 * la Organization por su Channel BUSINESS.
 */
interface OwnerNotifierInterface
{
    /**
     * @param  string[]  $bodyParameters
     *
     * @throws NotificationDeliveryException sin owner_phone, sin CENTRAL activo, o si el envío falla
     */
    public function sendTemplate(Organization $organization, string $templateName, string $language, array $bodyParameters): void;
}
