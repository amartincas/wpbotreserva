<?php

namespace App\Services;

use App\Models\WhatsAppPlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reporta a Meta los leads reales generados por clics en anuncios de
 * Click-to-WhatsApp, usando la Conversions API. Con esto el algoritmo de
 * Meta puede optimizar la entrega del anuncio hacia usuarios que de verdad
 * piden cotización, no solo hacia quien hace clic o escribe "hola".
 *
 * Credenciales globales (una sola cuenta de Meta para toda la plataforma,
 * independientemente de qué agencia/store esté corriendo el anuncio — todos
 * los anuncios apuntan al mismo número de WhatsApp compartido, que vive en
 * un solo Business Manager). Reutiliza el mismo access token que ya se usa
 * para enviar mensajes (permiso whatsapp_business_manage_events).
 */
class MetaConversionsApiService
{
    private const API_VERSION = 'v20.0';

    private static function adClickCacheKey(int $storeId, string $customerPhone): string
    {
        return "ad_click_id:{$storeId}:{$customerPhone}";
    }

    /**
     * Guarda el ctwa_clid del primer mensaje (clic en el anuncio) para poder
     * reportarlo más adelante, cuando (y si) ese cliente termine en un Lead
     * real — puede pasar varios turnos/días después del clic.
     */
    public static function rememberAdClick(int $storeId, string $customerPhone, string $ctwaClid): void
    {
        Cache::put(self::adClickCacheKey($storeId, $customerPhone), $ctwaClid, now()->addDays(7));

        Log::info('META_CAPI: ctwa_clid guardado', [
            'store_id' => $storeId,
            'customer_phone' => $customerPhone,
        ]);
    }

    /**
     * Si este cliente llegó por un anuncio (hay un ctwa_clid guardado), le
     * reporta a Meta el evento Lead. Si no hay nada guardado (lead orgánico,
     * sin anuncio de por medio), no hace nada — no es un error.
     */
    public static function reportLeadIfTracked(int $storeId, string $customerPhone): void
    {
        $ctwaClid = Cache::get(self::adClickCacheKey($storeId, $customerPhone));

        if (!$ctwaClid) {
            return;
        }

        self::sendLeadEvent($ctwaClid);
    }

    /**
     * Envío directo del evento Lead a la Conversions API. Público para poder
     * dispararlo manualmente desde el panel (botón de prueba) sin necesidad
     * de un ctwa_clid real guardado en caché.
     *
     * @return array{success: bool, message: string}
     */
    public static function sendLeadEvent(string $ctwaClid): array
    {
        $settings = WhatsAppPlatformSetting::current();

        if (empty($settings->meta_capi_dataset_id) || empty($settings->wa_access_token) || empty($settings->wa_business_account_id)) {
            $message = 'Dataset ID, Access Token o WABA ID no configurados en Configuración WhatsApp/IA.';
            Log::warning('META_CAPI: Envío omitido — falta configuración', ['message' => $message]);

            return ['success' => false, 'message' => $message];
        }

        $url = 'https://graph.facebook.com/' . self::API_VERSION . "/{$settings->meta_capi_dataset_id}/events";

        try {
            $response = Http::withToken($settings->wa_access_token)->post($url, [
                'data' => [[
                    'event_name' => 'LeadSubmitted',
                    'event_time' => now()->timestamp,
                    'action_source' => 'business_messaging',
                    'messaging_channel' => 'whatsapp',
                    'user_data' => [
                        'ctwa_clid' => $ctwaClid,
                        'whatsapp_business_account_id' => $settings->wa_business_account_id,
                    ],
                ]],
            ]);

            Log::info('META_CAPI: Lead event enviado', [
                'ctwa_clid' => $ctwaClid,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Evento Lead enviado correctamente a Meta.'];
            }

            $errorMessage = $response->json('error.message') ?? $response->body();
            return ['success' => false, 'message' => "Meta respondió con error: {$errorMessage}"];
        } catch (\Exception $e) {
            Log::error('META_CAPI: Excepción al enviar evento', [
                'ctwa_clid' => $ctwaClid,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()];
        }
    }
}
