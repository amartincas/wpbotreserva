<?php

namespace App\Domain\Tenancy;

use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Aggregate Root sin organization_id directo a propósito (Parte XVI) —
 * excepción documentada de Parte XI punto 5: un Channel puede servir a
 * varias organizaciones por diseño. Nunca le apliques BelongsToOrganization.
 */
#[Fillable([
    'provider',
    'channel_type',
    'phone_number',
    'phone_number_id',
    'business_account_id',
    'status',
    'credentials',
    'metadata',
])]
class Channel extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => ChannelProvider::class,
            'channel_type' => ChannelType::class,
            'status' => ChannelStatus::class,
            // Estructurado (Parte XVI: "la forma interna depende del provider,
            // validada en código vía un cast") — para meta_cloud_api guarda
            // ['access_token' => ..., 'verify_token' => ...]. Completa la
            // decisión de Hito 1; el ajuste es solo de cast, no de esquema.
            'credentials' => 'encrypted:array',
            'metadata' => 'array',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'channel_organization')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === ChannelStatus::ACTIVE;
    }
}
