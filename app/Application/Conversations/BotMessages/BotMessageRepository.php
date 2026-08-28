<?php

namespace App\Application\Conversations\BotMessages;

use App\Models\BotMessage;
use Illuminate\Support\Facades\Cache;

/**
 * Lee los textos del bot (RegistroNegocioAgent, GestionNegocioAgent,
 * ServiceResourceSelectionFlow y los extractores de campo) desde
 * bot_messages en vez de tenerlos hardcodeados — Fase 2 del plan de mejoras
 * post-piloto. Global (un solo set, sin multi-tenant).
 *
 * Cacheado con el store compartido (Redis en runtime real) e invalidado
 * activamente en BotMessage::booted() — a diferencia de
 * WhatsAppPlatformSetting::current() (memoizado solo por-request, volumen de
 * lectura bajísimo), acá el volumen es alto: un lookup por CADA mensaje que
 * manda el bot, así que amerita un store compartido con TTL en vez de
 * repetir la consulta en cada request.
 */
class BotMessageRepository
{
    public const CACHE_KEY = 'bot_messages.all';

    private const TTL_SECONDS = 3600;

    /**
     * @return array<string, string> key => template
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn () => BotMessage::query()->pluck('template', 'key')->all());
    }

    /**
     * null cuando la clave no existe (migración no corrida, o clave mal
     * escrita en el llamador) — el llamador decide el fallback, nunca se
     * lanza una excepción que corte la conversación por un texto faltante.
     *
     * @param  array<string, string|int>  $placeholders
     */
    public function render(string $key, array $placeholders = []): ?string
    {
        $template = $this->all()[$key] ?? null;

        if ($template === null || $placeholders === []) {
            return $template;
        }

        $replacements = [];

        foreach ($placeholders as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
