<?php

namespace App\Models;

use App\Enums\LeadLostReason;
use App\Enums\LeadPipelineStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id',
    'customer_phone',
    'customer_name',
    'meeting_point',
    'origin_city',
    'tour_date',
    'travelers_count',
    'product_service_name',
    'product_id',
    'product_name',
    'product_sale_price',
    'product_cost_price',
    'extras_detail',
    'extras_sale_total',
    'extras_cost_total',
    'comments',
    'total_amount',
    'status',
    'pipeline_stage',
    'lost_reason',
    'summary',
    'is_processed',
    'bot_active',
])]
class Lead extends Model
{
    use HasFactory;

    const STATUS_PENDIENTE = 'pendiente';
    const STATUS_ACEPTADO  = 'aceptado';
    const STATUS_CERRADO   = 'cerrado';
    const STATUS_CANCELADO = 'cancelado';

    const STATUS_MAP = [
        'aceptado'  => self::STATUS_ACEPTADO,
        'accepted'  => self::STATUS_ACEPTADO,
        'derivado'  => self::STATUS_ACEPTADO,
        'referred'  => self::STATUS_ACEPTADO,
        'cerrado'   => self::STATUS_CERRADO,
        'closed'    => self::STATUS_CERRADO,
        'cancelado' => self::STATUS_CANCELADO,
        'cancelled' => self::STATUS_CANCELADO,
        'canceled'  => self::STATUS_CANCELADO,
    ];

    const STATUS_MESSAGES = [
        // Se dispara cuando el asesor confirma con el botón/comando ACEPTADO
        // (o sus sinónimos DERIVADO) — confirma que un humano ya revisó la
        // solicitud y reitera que se pondrá en contacto.
        self::STATUS_ACEPTADO  => '👀 ¡El asesor ya revisó tu solicitud y en un momento se pondrá en contacto contigo!',
        self::STATUS_CERRADO   => '🎉 ¡Tu reserva quedó confirmada! Gracias por elegirnos. ¡Que disfrutes tu experiencia!',
        self::STATUS_CANCELADO => '❌ Tu reserva fue cancelada. Si tienes dudas, escríbenos y te ayudamos.',
    ];

    protected function casts(): array
    {
        return [
            'is_processed'              => 'boolean',
            'bot_active'                => 'boolean',
            'extras_detail'             => 'array',
            'product_sale_price'        => 'decimal:2',
            'product_cost_price'        => 'decimal:2',
            'extras_sale_total'         => 'decimal:2',
            'extras_cost_total'         => 'decimal:2',
            'pipeline_stage'            => LeadPipelineStage::class,
            'lost_reason'               => LeadLostReason::class,
            'pipeline_stage_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $lead) {
            if ($lead->isDirty('pipeline_stage')) {
                $lead->pipeline_stage_changed_at = now();
            }

            // El texto (product_service_name se usa en la plantilla de Meta
            // y en reportes) siempre debe coincidir EXACTO con el nombre
            // real del catálogo cuando hay un producto vinculado — nunca se
            // edita a mano, se deriva del product_id.
            if ($lead->isDirty('product_id') && $lead->product_id) {
                $lead->product_service_name = Product::find($lead->product_id)?->name
                    ?? $lead->product_service_name;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(LeadReminder::class);
    }

    /**
     * ID de negocio legible para el asesor (ej. BT-20260730-000241) — no
     * reemplaza la PK autoincremental, solo la muestra en un formato más
     * usable en el panel y en comunicaciones con el asesor.
     */
    public function businessId(): string
    {
        return 'BT-' . $this->created_at->format('Ymd') . '-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function markAsProcessed(): void
    {
        $this->update(['is_processed' => true]);
    }

    public function isProcessed(): bool
    {
        return $this->is_processed === true;
    }

    public static function unprocessed($storeId)
    {
        return static::where('store_id', $storeId)
            ->where('is_processed', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public static function resolveStatus(string $text): ?string
    {
        $normalized = strtolower(trim($text));
        return self::STATUS_MAP[$normalized] ?? null;
    }

    public static function statusMessage(string $status): ?string
    {
        return self::STATUS_MESSAGES[$status] ?? null;
    }

    /**
     * Alerta simple de seguimiento — basada en cuánto tiempo lleva el lead
     * sin moverse de su etapa actual (`pipeline_stage_changed_at`). Solo
     * cubre las 3 situaciones pedidas, nada más:
     *   - Nuevo sin contactar en 24h
     *   - Cotización enviada sin seguimiento en 3 días
     *   - Negociación detenida (3 días sin avanzar)
     *
     * @return array{label: string, color: string}|null
     */
    public function alert(): ?array
    {
        if (!$this->pipeline_stage_changed_at) {
            return null;
        }

        $hoursStuck = $this->pipeline_stage_changed_at->diffInHours(now());

        return match ($this->pipeline_stage) {
            LeadPipelineStage::NUEVO => $hoursStuck >= 24
                ? ['label' => '🟠 Sin contactar +24h', 'color' => 'warning']
                : null,
            LeadPipelineStage::COTIZACION_ENVIADA => $hoursStuck >= 72
                ? ['label' => '🔴 Cotización sin seguimiento +3 días', 'color' => 'danger']
                : null,
            LeadPipelineStage::NEGOCIANDO => $hoursStuck >= 72
                ? ['label' => '🟠 Negociación detenida', 'color' => 'warning']
                : null,
            default => null,
        };
    }

    /**
     * Calcula el margen bruto de este lead.
     * Margen = total_amount - product_cost_price - extras_cost_total
     */
    public function getMargin(): float
    {
        $total      = (float) preg_replace('/[^0-9.]/', '', $this->total_amount ?? '0');
        $cost       = (float) ($this->product_cost_price ?? 0);
        $extrasCost = (float) ($this->extras_cost_total ?? 0);
        return $total - $cost - $extrasCost;
    }
}
