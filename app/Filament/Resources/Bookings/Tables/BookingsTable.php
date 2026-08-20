<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Enums\BookingStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Negocio')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('customer.phone')
                    ->label('Teléfono')
                    ->searchable(),
                TextColumn::make('service.name')
                    ->label('Servicio'),
                TextColumn::make('starts_at')
                    ->label('Fecha y hora')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (BookingStatus $state): string => match ($state) {
                        BookingStatus::PENDING => 'gray',
                        BookingStatus::CONFIRMED => 'success',
                        BookingStatus::CANCELLED => 'danger',
                        BookingStatus::COMPLETED => 'primary',
                        BookingStatus::NO_SHOW => 'warning',
                    }),
                TextColumn::make('created_via')
                    ->label('Origen')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('organization_id')
                    ->relationship('organization', 'name')
                    ->label('Negocio'),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(BookingStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
