<?php

namespace App\Enums;

/**
 * MVP soporta un único proveedor. El campo existe como enum (no columna
 * libre) porque agregar un proveedor real siempre implica código nuevo
 * (una integración distinta) — no es una diferencia resoluble por dato
 * puro, a diferencia de Resource::subtype (Parte XVI).
 */
enum ChannelProvider: string
{
    case META_CLOUD_API = 'META_CLOUD_API';
}
