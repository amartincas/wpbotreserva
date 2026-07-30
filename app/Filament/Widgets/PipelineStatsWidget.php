<?php

namespace App\Filament\Widgets;

use App\Enums\LeadPipelineStage;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class PipelineStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $isSuperAdmin = Auth::user()?->is_super_admin;
        $storeId = Auth::user()?->store_id;

        $query = Lead::query();
        if (!$isSuperAdmin) {
            $query->where('store_id', $storeId);
        }

        $counts = (clone $query)
            ->selectRaw('pipeline_stage, count(*) as total')
            ->groupBy('pipeline_stage')
            ->pluck('total', 'pipeline_stage');

        $get = fn (LeadPipelineStage $stage) => (int) ($counts[$stage->value] ?? 0);

        $total = $counts->sum();
        $cerrados = $get(LeadPipelineStage::VENTA_REALIZADA);
        $perdidos = $get(LeadPipelineStage::VENTA_PERDIDA);
        $decididos = $cerrados + $perdidos;
        $tasaCierre = $decididos > 0 ? round(($cerrados / $decididos) * 100, 1) : 0;

        return [
            Stat::make('Nuevos', $get(LeadPipelineStage::NUEVO))
                ->description('Sin contactar aún')
                ->icon('heroicon-o-user-plus')
                ->color('success'),

            Stat::make('Contactados', $get(LeadPipelineStage::CONTACTADO))
                ->icon('heroicon-o-chat-bubble-left')
                ->color('warning'),

            Stat::make('Cotización enviada', $get(LeadPipelineStage::COTIZACION_ENVIADA))
                ->icon('heroicon-o-document-text')
                ->color('info'),

            Stat::make('Negociando', $get(LeadPipelineStage::NEGOCIANDO))
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning'),

            Stat::make('Ventas realizadas', $cerrados)
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Ventas perdidas', $perdidos)
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Tasa de cierre', $tasaCierre . '%')
                ->description("Sobre {$decididos} oportunidades decididas")
                ->icon('heroicon-o-arrow-trending-up')
                ->color($tasaCierre >= 30 ? 'success' : 'warning'),
        ];
    }
}
