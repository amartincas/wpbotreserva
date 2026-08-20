<?php

namespace App\Domain\Booking;

use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\Location;
use App\Enums\BookingCreatedVia;
use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aggregate Root más importante del sistema (Parte III). La creación real
 * (con la invariante de no-solapamiento) es responsabilidad de
 * BookingScheduler (Hito 2) — este modelo, en el Hito 1, es solo estructura.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'service_id',
    'customer_id',
    'starts_at',
    'ends_at',
    'duration_minutes',
    'status',
    'notes',
    'cancellation_policy_snapshot',
    'cancelled_at',
    'cancellation_reason',
    'created_via',
    'reminder_sent_at',
    'upcoming_reminder_sent_at',
])]
class Booking extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'created_via' => 'WHATSAPP',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
            'created_via' => BookingCreatedVia::class,
            'reminder_sent_at' => 'datetime',
            'upcoming_reminder_sent_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bookingResources(): HasMany
    {
        return $this->hasMany(BookingResource::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
