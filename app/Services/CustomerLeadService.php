<?php

namespace App\Services;

use App\Enums\CustomerLeadSource;
use App\Enums\CustomerLeadStatus;
use App\Models\CustomerLead;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

class CustomerLeadService
{
    /**
     * Busca o crea un CustomerLead cuando llega un mensaje de WhatsApp.
     * Punto de entrada principal — se llama desde el WhatsAppController.
     */
    public static function findOrCreateLead(
        Store $store,
        string $phone,
        ?string $source = null
    ): CustomerLead {
        $customerLead = CustomerLead::where('store_id', $store->id)
            ->where('customer_phone', $phone)
            ->first();

        if (!$customerLead) {
            $customerLead = CustomerLead::create([
                'store_id'        => $store->id,
                'customer_phone'  => $phone,
                'first_contact_at'=> now(),
                'last_contact_at' => now(),
                'first_source'    => $source ?? CustomerLeadSource::WHATSAPP_DIRECT->value,
                'status'          => CustomerLeadStatus::NEW->value,
            ]);

            Log::info('CRM: Nuevo lead capturado', [
                'store_id'    => $store->id,
                'phone'       => $phone,
                'source'      => $customerLead->first_source,
            ]);
        } else {
            static::updateLastContact($customerLead);
        }

        return $customerLead;
    }

    /**
     * Actualiza el timestamp de último contacto.
     */
    public static function updateLastContact(CustomerLead $customerLead): void
    {
        $customerLead->update(['last_contact_at' => now()]);
    }

    /**
     * Actualiza el nombre del lead si aún no lo tiene.
     * Se llama desde el Job cuando la IA extrae el nombre del cliente.
     */
    public static function updateCustomerName(
        CustomerLead $customerLead,
        ?string $name
    ): void {
        if (empty($customerLead->customer_name) && !empty($name)) {
            $customerLead->update(['customer_name' => $name]);

            Log::info('CRM: Nombre del lead actualizado', [
                'customer_lead_id' => $customerLead->id,
                'name'             => $name,
            ]);
        }
    }

    /**
     * Registra el primer producto que despertó interés en el lead.
     * Solo se guarda si first_product_id aún no está definido.
     */
    public static function captureFirstProduct(
        CustomerLead $customerLead,
        int $productId
    ): void {
        if (empty($customerLead->first_product_id)) {
            $customerLead->update([
                'first_product_id' => $productId,
                'status'           => CustomerLeadStatus::INTERESTED->value,
            ]);

            Log::info('CRM: Primer producto de interés capturado', [
                'customer_lead_id' => $customerLead->id,
                'product_id'       => $productId,
            ]);
        }
    }

    /**
     * Registra un pedido confirmado.
     * Incrementa métricas, actualiza estado y vincula el lead/pedido.
     * Se llama desde el Job cuando se crea un registro en la tabla leads.
     */
    public static function registerOrder(
        CustomerLead $customerLead,
        Lead $order
    ): void {
        $orderValue = (float) preg_replace(
            '/[^0-9.]/',
            '',
            $order->total_amount ?? '0'
        );

        $isFirstOrder  = $customerLead->total_orders === 0;
        $newTotalOrders = $customerLead->total_orders + 1;
        $newClv         = $customerLead->customer_lifetime_value + $orderValue;

        $newStatus = $newTotalOrders === 1
            ? CustomerLeadStatus::CUSTOMER->value
            : CustomerLeadStatus::REPEAT_CUSTOMER->value;

        $updateData = [
            'total_orders'            => $newTotalOrders,
            'customer_lifetime_value' => $newClv,
            'last_order_at'           => now(),
            'status'                  => $newStatus,
            'last_product_id'         => $order->first_product_id
                                        ?? $customerLead->last_product_id,
        ];

        if ($isFirstOrder) {
            $updateData['conversion_date'] = now();
        }

        $customerLead->update($updateData);

        // Vincular el pedido al CustomerLead con FK real
        $order->update(['customer_lead_id' => $customerLead->id]);

        Log::info('CRM: Pedido registrado en CustomerLead', [
            'customer_lead_id'  => $customerLead->id,
            'order_id'          => $order->id,
            'total_orders'      => $newTotalOrders,
            'clv'               => $newClv,
            'status'            => $newStatus,
            'is_first_order'    => $isFirstOrder,
        ]);
    }

    /**
     * Registra en el CRM el pedido/reserva que se acaba de crear en `leads` —
     * actualiza nombre, primer producto de interés, y las métricas del
     * CustomerLead (total_orders, CLV, status). Compartido entre la creación
     * automática del bot y la conversión manual (comando CONVERTIR / botón
     * del panel), para que ambos caminos alimenten el mismo CRM por igual.
     */
    public static function registerOrderFromLead(Store $store, string $customerPhone, Lead $lead, array $leadData): void
    {
        try {
            $customerLead = CustomerLead::where('store_id', $store->id)
                ->where('customer_phone', $customerPhone)
                ->first();

            if (!$customerLead) {
                Log::warning('CRM: CustomerLead no encontrado para registrar pedido', [
                    'store_id' => $store->id,
                    'phone'    => $customerPhone,
                ]);
                return;
            }

            static::updateCustomerName($customerLead, $leadData['customer_name'] ?? null);

            if ($lead->product_name) {
                $product = Product::where('store_id', $store->id)
                    ->where('name', 'like', '%' . $lead->product_name . '%')
                    ->first();

                if ($product) {
                    static::captureFirstProduct($customerLead, $product->id);
                }
            }

            static::registerOrder($customerLead, $lead);
        } catch (\Exception $e) {
            Log::error('CRM: Error al registrar pedido', [
                'store_id' => $store->id,
                'lead_id'  => $lead->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calcula métricas CRM para un store en un rango de fechas.
     */
    public static function calculateMetrics(int $storeId, $from = null, $to = null): array
    {
        $query = CustomerLead::where('store_id', $storeId);

        if ($from && $to) {
            $query->whereBetween('first_contact_at', [$from, $to]);
        }

        $leads = $query->get();
        $total = $leads->count();

        $byStatus = $leads->groupBy(fn ($l) => $l->status->value ?? $l->status);

        $customers = ($byStatus->get('CUSTOMER', collect())->count())
                   + ($byStatus->get('REPEAT_CUSTOMER', collect())->count());

        $conversionRate = $total > 0
            ? round(($customers / $total) * 100, 1)
            : 0;

        $totalRevenue = $leads->sum('customer_lifetime_value');
        $avgClv       = $customers > 0
            ? round($totalRevenue / $customers, 0)
            : 0;

        return [
            'total_leads'       => $total,
            'new'               => $byStatus->get('NEW', collect())->count(),
            'contacted'         => $byStatus->get('CONTACTED', collect())->count(),
            'interested'        => $byStatus->get('INTERESTED', collect())->count(),
            'customers'         => $byStatus->get('CUSTOMER', collect())->count(),
            'repeat_customers'  => $byStatus->get('REPEAT_CUSTOMER', collect())->count(),
            'inactive'          => $byStatus->get('INACTIVE', collect())->count(),
            'conversion_rate'   => $conversionRate,
            'total_revenue'     => $totalRevenue,
            'avg_clv'           => $avgClv,
        ];
    }
}
