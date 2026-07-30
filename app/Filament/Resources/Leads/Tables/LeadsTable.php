<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadPipelineStage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['reminders as pending_reminders_count' => fn ($q) => $q->where('is_done', false)])
                ->with(['reminders' => fn ($q) => $q->where('is_done', false)->orderBy('due_at')]))
            ->columns([
                TextColumn::make('store.name')
                    ->label('Store')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('customer_name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_phone')
                    ->label('Teléfono')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('product_service_name')
                    ->label('Producto/Servicio')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
                TextColumn::make('pipeline_stage')
                    ->label('Etapa de Venta')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof LeadPipelineStage
                        ? $state->label()
                        : LeadPipelineStage::from($state)->label())
                    ->color(fn ($state) => $state instanceof LeadPipelineStage
                        ? $state->color()
                        : LeadPipelineStage::from($state)->color())
                    ->sortable(),
                TextColumn::make('alert')
                    ->label('Alerta')
                    ->state(fn ($record) => $record->alert()['label'] ?? null)
                    ->badge()
                    ->color(fn ($record) => $record->alert()['color'] ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('pending_reminders_count')
                    ->label('Recordatorios')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state > 0 ? "🔔 {$state}" : '—')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->tooltip(fn ($record) => $record->reminders->isEmpty()
                        ? null
                        : $record->reminders
                            ->map(fn ($r) => '• ' . $r->note . ($r->due_at ? ' (' . $r->due_at->format('d/m/Y') . ')' : ''))
                            ->join("\n"))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Fecha de Ingreso')
                    ->since()
                    ->sortable(),
                TextColumn::make('summary')
                    ->label('Resumen')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        return $column->getState();
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('comision')
                    ->label('Comisión')
                    ->state(fn ($record) => $record->status === \App\Models\Lead::STATUS_CERRADO ? $record->getMargin() : null)
                    ->money('COP', locale: 'es_CO')
                    ->placeholder('—')
                    ->sortable(false)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_id')
                    ->label('ID')
                    ->state(fn ($record) => $record->businessId())
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_processed')
                    ->label('Processed (legacy)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_processed')
                    ->label('Processed Status')
                    ->placeholder('All')
                    ->trueLabel('Processed')
                    ->falseLabel('Not Processed'),
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label('Store'),
                SelectFilter::make('pipeline_stage')
                    ->label('Etapa de Venta')
                    ->options(LeadPipelineStage::options()),
                Filter::make('has_alert')
                    ->label('Solo con alerta')
                    ->toggle()
                    ->query(fn ($query) => $query->where(function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('pipeline_stage', LeadPipelineStage::NUEVO->value)
                                ->where('pipeline_stage_changed_at', '<=', now()->subHours(24));
                        })->orWhere(function ($q2) {
                            $q2->where('pipeline_stage', LeadPipelineStage::COTIZACION_ENVIADA->value)
                                ->where('pipeline_stage_changed_at', '<=', now()->subHours(72));
                        })->orWhere(function ($q2) {
                            $q2->where('pipeline_stage', LeadPipelineStage::NEGOCIANDO->value)
                                ->where('pipeline_stage_changed_at', '<=', now()->subHours(72));
                        });
                    })),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
