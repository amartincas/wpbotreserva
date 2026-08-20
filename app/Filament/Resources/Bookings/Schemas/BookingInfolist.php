<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Enums\BookingStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reserva')
                    ->columns(3)
                    ->components([
                        TextEntry::make('organization.name')
                            ->label('Negocio'),
                        TextEntry::make('location.name')
                            ->label('Ubicación')
                            ->placeholder('-'),
                        TextEntry::make('service.name')
                            ->label('Servicio'),
                        TextEntry::make('starts_at')
                            ->label('Inicio')
                            ->dateTime(),
                        TextEntry::make('ends_at')
                            ->label('Fin')
                            ->dateTime(),
                        TextEntry::make('duration_minutes')
                            ->label('Duración (min)'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (BookingStatus $state): string => match ($state) {
                                BookingStatus::PENDING => 'gray',
                                BookingStatus::CONFIRMED => 'success',
                                BookingStatus::CANCELLED => 'danger',
                                BookingStatus::COMPLETED => 'primary',
                                BookingStatus::NO_SHOW => 'warning',
                            }),
                        TextEntry::make('created_via')
                            ->label('Origen')
                            ->badge(),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make('Cliente')
                    ->columns(2)
                    ->components([
                        TextEntry::make('customer.name')
                            ->label('Nombre')
                            ->placeholder('-'),
                        TextEntry::make('customer.phone')
                            ->label('Teléfono'),
                    ]),
                Section::make('Cancelación')
                    ->columns(2)
                    ->components([
                        TextEntry::make('cancelled_at')
                            ->label('Cancelada')
                            ->dateTime(),
                        TextEntry::make('cancellation_reason')
                            ->label('Motivo')
                            ->placeholder('-'),
                    ])
                    ->visible(fn ($record) => $record->status === BookingStatus::CANCELLED),
                Section::make('Recordatorios')
                    ->columns(2)
                    ->components([
                        TextEntry::make('reminder_sent_at')
                            ->label('Aviso al dueño (reserva vencida)')
                            ->dateTime()
                            ->placeholder('No enviado'),
                        TextEntry::make('upcoming_reminder_sent_at')
                            ->label('Recordatorio al cliente (24h antes)')
                            ->dateTime()
                            ->placeholder('No enviado'),
                    ]),
                Section::make('Registro')
                    ->columns(2)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('Creada')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Actualizada')
                            ->dateTime(),
                    ]),
            ]);
    }
}
