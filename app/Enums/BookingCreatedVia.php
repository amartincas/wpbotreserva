<?php

namespace App\Enums;

enum BookingCreatedVia: string
{
    case WHATSAPP = 'WHATSAPP';
    case ADMIN = 'ADMIN';
}
