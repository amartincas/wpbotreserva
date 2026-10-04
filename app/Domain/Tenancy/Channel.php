<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\CentralChannelLinkException;
use App\Domain\Tenancy\Exceptions\DuplicateCentralChannelException;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Aggregate Root sin organization_id directo a propósito — excepción
 * documentada de Parte XI punto 5: un Channel puede existir antes de que
 * cualquier Organization se registre contra él (estado inicial normal,
 * nunca un error). Nunca le apliques BelongsToOrganization.
 *
 * Channel → 0 o 1 Organization (regla de negocio definitiva, Fase 6),
 * forzada también por UNIQUE(channel_id) en channel_organization —
 * `organizations()` usa BelongsToMany solo porque la relación pasa por una
 * tabla pivot, no porque un Channel pueda estar vinculado a más de una
 * Organization.
 *
 * `role` (ChannelRole) distingue el número CENTRAL de WpbotReserva (nunca
 * vinculado a una Organization) del WhatsApp propio de un negocio
 * (BUSINESS) — independiente de `channel_type`, que solo describe el medio.
 *
 * Invariantes del CENTRAL (B2), chequeadas al guardar: como máximo uno
 * ACTIVE a la vez, y nunca pasa a CENTRAL un Channel que todavía tiene una
 * Organization vinculada. El otro lado (vincular un CENTRAL) lo cuida el
 * pivot ChannelOrganization.
 */
#[Fillable([
    'provider',
    'channel_type',
    'role',
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

    // Espeja el default de la migración — sin esto, una instancia recién
    // creada con Model::create() no ve el default hasta un fresh()/refresh().
    protected $attributes = [
        'role' => 'BUSINESS',
    ];

    // B9: el access token nunca sale del modelo al serializarlo (arrays,
    // JSON, componentes de Filament) — solo MetaWhatsAppClient y el
    // verificador lo leen, por atributo.
    protected $hidden = [
        'credentials',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $channel) {
            if (! $channel->isCentral()) {
                return;
            }

            if ($channel->exists && $channel->isDirty('role') && $channel->organizations()->exists()) {
                throw new CentralChannelLinkException(
                    "El Channel #{$channel->id} todavía está vinculado a una Organization — desvincularlo antes de convertirlo en CENTRAL."
                );
            }

            if (! $channel->isActive()) {
                return;
            }

            $anotherActiveCentral = static::query()
                ->where('role', ChannelRole::CENTRAL->value)
                ->where('status', ChannelStatus::ACTIVE->value)
                ->when($channel->exists, fn (Builder $query) => $query->whereKeyNot($channel->getKey()))
                ->exists();

            if ($anotherActiveCentral) {
                throw new DuplicateCentralChannelException('Ya existe otro Channel CENTRAL activo.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'provider' => ChannelProvider::class,
            'channel_type' => ChannelType::class,
            'role' => ChannelRole::class,
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
            ->using(ChannelOrganization::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === ChannelStatus::ACTIVE;
    }

    public function isCentral(): bool
    {
        return $this->role === ChannelRole::CENTRAL;
    }

    public function isBusiness(): bool
    {
        return $this->role === ChannelRole::BUSINESS;
    }

    public function scopeBusiness(Builder $query): Builder
    {
        return $query->where('role', ChannelRole::BUSINESS->value);
    }

    /**
     * B9: la conexión de WhatsApp de negocio de esta Organization que
     * todavía espera la verificación de Meta — un Channel BUSINESS en
     * PENDING_VERIFICATION, sin vínculo todavía (la Organization destino
     * vive en metadata hasta que se verifica).
     */
    public function scopePendingBusinessFor(Builder $query, Organization $organization): Builder
    {
        return $query
            ->where('role', ChannelRole::BUSINESS->value)
            ->where('status', ChannelStatus::PENDING_VERIFICATION->value)
            ->where('metadata->pending_organization_id', $organization->id);
    }
}
