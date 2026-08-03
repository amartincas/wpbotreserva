<?php

namespace App\Domain\Scheduling;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\Location;
use App\Enums\ScheduleExceptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aggregate Root propio (Parte III/V) — puede aplicar a un Resource, a un
 * Location, o a toda la Organization (ambos null); se referencia por ID,
 * nunca se anida dentro de Resource/Location.
 */
#[Fillable(['organization_id', 'location_id', 'resource_id', 'date', 'start_time', 'end_time', 'type', 'is_available', 'reason'])]
class ScheduleException extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => ScheduleExceptionType::class,
            'is_available' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
