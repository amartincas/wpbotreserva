<?php

namespace App\Domain\Conversational;

use Carbon\CarbonImmutable;

/**
 * Mensaje ya normalizado (texto plano, sin importar si el original era
 * audio transcripto, caption de imagen, etc. — esa normalización es del
 * Hito 7) — el único input que conoce InboundMessageRouter. phoneNumberId
 * es el identificador estable del proveedor (Parte XVI), nunca el número
 * visible.
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $phoneNumberId,
        public readonly string $fromPhone,
        public readonly string $text,
        public readonly CarbonImmutable $receivedAt,
    ) {}
}
