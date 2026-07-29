<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'ai_provider',
    'ai_model',
    'ai_api_key',
    'wa_access_token',
    'wa_phone_number_id',
    'wa_business_account_id',
    'wa_verify_token',
    'meta_capi_dataset_id',
])]
class WhatsAppPlatformSetting extends Model
{
    protected $table = 'whatsapp_platform_settings';

    protected static ?self $cached = null;

    protected function casts(): array
    {
        return [
            'ai_provider' => 'string',
            'ai_api_key' => 'encrypted',
            'wa_access_token' => 'encrypted',
            'wa_verify_token' => 'encrypted',
        ];
    }

    /**
     * The single global row (id=1) holding platform-wide WhatsApp/AI credentials.
     * Memoized per-request (not a shared cache store) to avoid re-querying on
     * every access without the pitfalls of serializing an Eloquent model
     * through a cross-process cache.
     */
    public static function current(): self
    {
        return static::$cached ??= static::firstOrCreate(['id' => 1]);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::$cached = null);
    }
}
