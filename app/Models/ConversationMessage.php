<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Trazabilidad mínima de mensajes (post-E2E Fase 1, Hallazgo 5) — registro
 * append-only de solo observabilidad, nunca leído por lógica de negocio,
 * clasificación, routing ni reservas. Nunca se edita una fila ya creada,
 * por eso $timestamps = false (la tabla no tiene updated_at) y created_at
 * se pasa explícito en cada ::create().
 */
#[Fillable(['channel_id', 'organization_id', 'customer_phone', 'direction', 'message_id', 'body', 'created_at'])]
class ConversationMessage extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
