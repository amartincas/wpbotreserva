<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Maneja el borrador de datos (order_draft) que ProcessWhatsAppMessage
 * acumula turno a turno mientras la IA conversa con el cliente, antes de
 * que exista una Reserva formal. Centraliza la conversión manual a Lead
 * para que el comando de WhatsApp (CONVERTIR) y el botón del panel de chat
 * usen exactamente la misma lógica.
 */
class LeadDraftService
{
    public static function draftKey(Store $store, string $customerPhone): string
    {
        return "order_draft:{$store->id}:{$customerPhone}";
    }

    public static function getDraft(Store $store, string $customerPhone): array
    {
        return Cache::get(self::draftKey($store, $customerPhone), []);
    }

    public static function hasUsableDraft(array $draft): bool
    {
        return !empty($draft['customer_name']) || !empty($draft['product_service_name']);
    }

    /**
     * Crea la Reserva a partir del borrador acumulado y notifica al asesor.
     *
     * @return array{success: bool, lead: ?Lead, notified: bool, error: ?string}
     */
    public static function convertDraftToLead(Store $store, string $customerPhone): array
    {
        $draft = self::getDraft($store, $customerPhone);

        if (!self::hasUsableDraft($draft)) {
            return [
                'success'  => false,
                'lead'     => null,
                'notified' => false,
                'error'    => 'No hay información pendiente guardada para ese número (o ya expiró — el borrador dura 4 horas desde el último mensaje del cliente).',
            ];
        }

        $lead = Lead::create([
            'store_id'             => $store->id,
            'customer_phone'       => $customerPhone,
            'customer_name'        => $draft['customer_name'] ?? null,
            'meeting_point'        => $draft['meeting_point'] ?? null,
            'origin_city'          => $draft['origin_city'] ?? null,
            'tour_date'            => $draft['tour_date'] ?? null,
            'travelers_count'      => $draft['travelers_count'] ?? null,
            'product_service_name' => $draft['product_service_name'] ?? null,
            'comments'             => $draft['comments'] ?? null,
            'total_amount'         => $draft['total_amount'] ?? null,
            'summary'              => 'Reserva creada manualmente a partir de los datos acumulados en la conversación.',
            'is_processed'         => false,
        ]);

        Cache::forget(self::draftKey($store, $customerPhone));

        $notified = WhatsAppService::notifyAdvisorOfLead($store, $lead, $customerPhone, $draft);

        return [
            'success'  => true,
            'lead'     => $lead,
            'notified' => $notified,
            'error'    => null,
        ];
    }
}
