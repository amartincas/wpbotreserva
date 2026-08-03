<?php

namespace App\Domain\Scheduling;

use App\Domain\Tenancy\Location;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario semanal recurrente — de un Resource propio, o el horario general
 * de un Location que los recursos sin horario propio heredan (Parte I §8).
 * Dato de Resource/Location, no un aggregate independiente (Parte III).
 */
#[Fillable(['resource_id', 'location_id', 'weekday', 'start_time', 'end_time'])]
class ResourceSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
