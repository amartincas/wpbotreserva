<?php

namespace App\Domain\Conversational;

use App\Domain\Shared\PhoneNumberCast;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aggregate mínimo (Parte XI punto 7 / Parte XII) — solo lo indispensable
 * para que el Router (Hito 4) sepa a qué agente despachar cada mensaje.
 * La memoria de trabajo rica sigue viviendo en Cache (código existente),
 * no acá — recorte explícito de Parte XII.
 */
#[Fillable(['organization_id', 'customer_phone', 'current_agent'])]
class ConversationSession extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'customer_phone' => PhoneNumberCast::class,
        ];
    }
}
