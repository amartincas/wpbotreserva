<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('store.name')
                    ->label('Agencia'),
                TextEntry::make('name')
                    ->label('Nombre'),
                TextEntry::make('description')
                    ->label('Descripción')
                    ->columnSpanFull(),
                TextEntry::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'product' => 'Producto',
                        'service' => 'Servicio',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'product' => 'blue',
                        'service' => 'green',
                        default => 'gray',
                    }),
                TextEntry::make('price')
                    ->label('Precio')
                    ->money(),
                TextEntry::make('stock')
                    ->numeric()
                    ->label(function ($record) {
                        return $record?->type === 'service'
                            ? 'Disponibilidad'
                            : 'Cantidad en Stock';
                    }),
                TextEntry::make('ai_sales_strategy')
                    ->label('Estrategia de Venta para la IA')
                    ->columnSpanFull()
                    ->placeholder('No definido'),
                TextEntry::make('faq_context')
                    ->label('FAQ y Contexto Operativo')
                    ->columnSpanFull()
                    ->placeholder('No definido'),
                TextEntry::make('required_customer_info')
                    ->label('Datos Obligatorios del Cliente')
                    ->columnSpanFull()
                    ->placeholder('No definido'),
                TextEntry::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
