<?php

namespace App\Application\Booking\Notifications;

use App\Domain\Tenancy\Organization;

/**
 * Salida de ProfessionalRecipientResolver — ya validada (misma Organization
 * que el Booking, teléfono presente y normalizado a E.164). Los 3 listeners
 * de Fase 2B la consumen tal cual, sin volver a validar nada.
 */
final class ResolvedProfessionalRecipient
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $contactPhone,
        public readonly string $resourceName,
    ) {}
}
