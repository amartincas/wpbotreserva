<?php

namespace App\Domain\Tenancy;

use App\Domain\Booking\Booking;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'timezone',
    'locale',
    'currency',
    'is_active',
    'suspended_at',
    'suspension_reason',
    'owner_phone',
])]
class Organization extends Model
{
    use HasFactory;

    // Espeja los defaults de la migración — sin esto, una instancia recién
    // creada con Model::create() no ve el default hasta un fresh()/refresh().
    protected $attributes = [
        'timezone' => 'America/Bogota',
        'locale' => 'es',
        'currency' => 'COP',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * N:N por diseño (Parte XVI) — un canal puede atender varias
     * organizaciones; nunca asumir que hay uno solo.
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_organization')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
