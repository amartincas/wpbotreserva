<?php

namespace App\Domain\Conversational;

use App\Domain\Shared\PhoneNumberCast;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\Channel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aggregate mínimo (Parte XI punto 7 / Parte XII) — solo lo indispensable
 * para que el Router (Hito 4) sepa a qué agente despachar cada mensaje.
 * Identidad por (channel_id, customer_phone): un mismo teléfono puede tener
 * una sesión distinta por cada Channel con el que conversa (Parte XVI). La
 * memoria de trabajo rica sigue viviendo en Cache (código existente), no
 * acá — recorte explícito de Parte XII.
 *
 * current_intent guarda el último Intent clasificado (nunca un Agent
 * concreto) — es lo que permite a ConversationContinuityStrategy dar
 * continuidad sin volver a clasificar contenido cada mensaje.
 */
#[Fillable(['channel_id', 'organization_id', 'customer_phone', 'current_intent'])]
class ConversationSession extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'customer_phone' => PhoneNumberCast::class,
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
