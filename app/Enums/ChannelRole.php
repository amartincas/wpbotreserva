<?php

namespace App\Enums;

/**
 * Función del Channel dentro de WpbotReserva — ortogonal a ChannelType
 * (que describe el MEDIO, ej. WHATSAPP) y a ChannelProvider (quién lo
 * opera, ej. META_CLOUD_API).
 *
 *  - CENTRAL: número propio de WpbotReserva. Atiende onboarding y
 *    administración de todos los owners, y envía las alertas al owner.
 *    Nunca pertenece a una Organization.
 *  - BUSINESS: WhatsApp propio de un negocio. Pertenece a una sola
 *    Organization y es el número que usan sus clientes.
 *
 * BUSINESS es el default de la columna: todo Channel existente antes de
 * esta separación era, de hecho, un número vinculado a una Organization.
 */
enum ChannelRole: string
{
    case CENTRAL = 'CENTRAL';
    case BUSINESS = 'BUSINESS';
}
