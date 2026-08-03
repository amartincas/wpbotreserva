<?php

namespace App\Domain\Scheduling;

use App\Domain\Booking\BookingResource;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\Location;
use App\Enums\ResourceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'location_id',
    'resource_type',
    'subtype',
    'display_name',
    'capacity',
    'contact_phone',
    'user_id',
    'is_active',
])]
class Resource extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $attributes = [
        'capacity' => 1,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => ResourceType::class,
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'resource_service');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ResourceSchedule::class);
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    public function bookingResources(): HasMany
    {
        return $this->hasMany(BookingResource::class);
    }
}
