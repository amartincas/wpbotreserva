<?php

namespace App\Domain\Tenancy;

use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use App\Domain\Scheduling\ScheduleException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'name',
    'address',
    'city',
    'country_code',
    'timezone',
    'phone',
    'is_active',
])]
class Location extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $attributes = [
        'country_code' => 'CO',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ResourceSchedule::class);
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }
}
