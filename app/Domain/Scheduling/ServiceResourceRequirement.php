<?php

namespace App\Domain\Scheduling;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entidad interna del aggregate Service (Parte III) — no tiene sentido ni
 * se consulta fuera de su Service.
 */
#[Fillable(['service_id', 'resource_type', 'subtype', 'quantity'])]
class ServiceResourceRequirement extends Model
{
    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'quantity' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
