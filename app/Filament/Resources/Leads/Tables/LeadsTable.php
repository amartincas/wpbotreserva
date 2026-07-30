<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadPipelineStage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount([
                'reminders as pending_reminders_count' => fn ($q) => $q->where('is_done', false),
            ]))
            ->columns([
                TextColumn::make('store.name')
                    ->label('Store Name')
                    ->searchable()
                    ->sortable()
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('customer_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_phone')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('product_service_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('summary')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        return $column->getState();
                    })
                    ->wrap(),
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
                TextColumn::make('comision')
                    ->label('Comisión')
                    ->state(fn ($record) => $record->status === \App\Models\Lead::STATUS_CERRADO ? $record->getMargin() : null)
                    ->money('COP', locale: 'es_CO')
                    ->placeholder('—')
                    ->sortable(false),
                TextColumn::make('pending_reminders_count')
                    ->label('Recordatorios')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state > 0 ? "🔔 {$state}" : '—')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('business_id')
                    ->label('ID')
                    ->state(fn ($record) => $record->businessId())
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
                ToggleColumn::make('is_processed')
                    ->label('Processed')
                    ->sortable(),
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
