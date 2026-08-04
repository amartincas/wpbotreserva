<?php

namespace App\Application\Contracts;

use App\Domain\Tenancy\Channel;

/**
 * Primera resolución de la secuencia (Parte XVI) — identifica el Channel a
 * partir del phone_number_id del proveedor, nunca del número visible. No
 * decide activo/inactivo (eso lo resuelve el caller vía Channel::isActive())
 * — este contrato se limita a la búsqueda.
 */
interface ChannelResolverInterface
{
    public function resolve(string $phoneNumberId): ?Channel;
}
