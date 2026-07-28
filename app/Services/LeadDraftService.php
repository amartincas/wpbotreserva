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
     * Campos obligatorios para considerar el borrador "completo" — se usa
     * para decidir si mostrar la franja de "Datos capturados" en el panel
     * de chat. El teléfono no se valida acá porque siempre está disponible
     * (es el número de la propia conversación, no un dato que la IA extraiga).
     * Deliberadamente MÁS estricto que hasUsableDraft(): esa se usa para el
     * rescate manual (comando CONVERTIR / botón), donde sí queremos permitir
     * crear la reserva aunque falte algún dato — acá no, la franja del panel
     * solo debe aparecer cuando ya está todo.
     */
    public static function isComplete(array $draft): bool
    {
        $requiredFields = [
            'customer_name',
            'product_service_name',
            'origin_city',
            'travelers_count',
            'tour_date',
        ];

        foreach ($requiredFields as $field) {
            if (empty($draft[$field])) {
                return false;
            }
        }

        return true;
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
