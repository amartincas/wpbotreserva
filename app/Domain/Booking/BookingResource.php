<?php

namespace App\Domain\Booking;

use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ServiceResourceRequirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entidad interna del aggregate Booking (Parte III) — qué recurso(s)
 * concretos quedaron asignados a esta reserva. Sin sentido fuera de ella,
 * por eso no lleva organization_id ni usa BelongsToOrganization.
 */
#[Fillable(['booking_id', 'resource_id', 'fulfills_requirement_id'])]
class BookingResource extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function fulfillsRequirement(): BelongsTo
    {
        return $this->belongsTo(ServiceResourceRequirement::class, 'fulfills_requirement_id');
    }
}
