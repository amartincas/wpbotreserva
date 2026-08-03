<?php

namespace App\Enums;

/**
 * Ciclo de vida de Channel (Parte XVI). Las transiciones automáticas
 * (detectar un bloqueo real de Meta) son roadmap — acá solo se declara el
 * vocabulario y el estado se cambia manualmente hasta entonces.
 */
enum ChannelStatus: string
{
    case PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    case ACTIVE = 'ACTIVE';
    case DISCONNECTED = 'DISCONNECTED';
    case SUSPENDED = 'SUSPENDED';
    case BLOCKED_BY_META = 'BLOCKED_BY_META';
    case ERROR = 'ERROR';
}
