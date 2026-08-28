<?php

namespace App\Models;

use App\Application\Conversations\BotMessages\BotMessageRepository;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'group', 'template', 'description'])]
class BotMessage extends Model
{
    protected $table = 'bot_messages';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(BotMessageRepository::CACHE_KEY));
        static::deleted(fn () => Cache::forget(BotMessageRepository::CACHE_KEY));
    }
}
