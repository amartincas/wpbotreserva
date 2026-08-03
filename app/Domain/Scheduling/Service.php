<?php

namespace App\Domain\Scheduling;

use App\Domain\Booking\Booking;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
    'description',
    'duration_minutes',
    'buffer_minutes',
    'capacity_per_slot',
    'price',
    'currency',
    'cancellation_policy',
    'is_active',
])]
class Service extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $attributes = [
        'buffer_minutes' => 0,
        'capacity_per_slot' => 1,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'capacity_per_slot' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function resourceRequirements(): HasMany
    {
        return $this->hasMany(ServiceResourceRequirement::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'resource_service');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
