<?php

namespace App\Domain\CRM;

use App\Domain\Booking\Booking;
use App\Domain\Shared\PhoneNumberCast;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aggregate Root minimalista (Parte III) — solo identidad básica. Cualquier
 * enriquecimiento (segmentos, campañas) vive en un futuro contexto de
 * CRM/Marketing que referencia este ID, nunca lo extiende directamente.
 */
#[Fillable([
    'organization_id',
    'phone',
    'name',
    'email',
    'preferred_locale',
    'timezone',
    'marketing_opt_in',
    'first_seen_at',
    'last_interaction_at',
])]
class Customer extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'phone' => PhoneNumberCast::class,
            'marketing_opt_in' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
