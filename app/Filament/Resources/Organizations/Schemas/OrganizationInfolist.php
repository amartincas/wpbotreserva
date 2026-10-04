<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Domain\Tenancy\Channel;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del negocio')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('owner_phone')
                            ->label('Teléfono del dueño')
                            ->placeholder('No configurado'),
                        IconEntry::make('is_active')
                            ->label('Activo')
                            ->boolean(),
                        TextEntry::make('suspension_reason')
                            ->label('Motivo de suspensión')
                            ->placeholder('-'),
                        TextEntry::make('timezone')
                            ->label('Zona horaria'),
                        TextEntry::make('locale')
                            ->label('Idioma'),
                        TextEntry::make('currency')
                            ->label('Moneda'),
                        TextEntry::make('created_at')
                            ->label('Registrado')
                            ->dateTime(),
                    ]),
                Section::make('Ubicaciones')
                    ->components([
                        RepeatableEntry::make('locations')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('name')->label('Nombre'),
                                TextEntry::make('city')->label('Ciudad'),
                                TextEntry::make('address')->label('Dirección')->placeholder('-'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record) => $record->locations()->exists()),
                Section::make('Servicios')
                    ->components([
                        RepeatableEntry::make('services')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('name')->label('Nombre'),
                                TextEntry::make('duration_minutes')->label('Duración (min)'),
                                TextEntry::make('is_active')->label('Activo')->badge(),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record) => $record->services()->exists()),
                Section::make('Canales')
                    ->components([
                        RepeatableEntry::make('channels')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('phone_number')->label('Número'),
                                TextEntry::make('role')->label('Rol')->badge(),
                                TextEntry::make('provider')->label('Proveedor')->badge(),
                                TextEntry::make('status')->label('Estado')->badge(),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn ($record) => $record->channels()->exists()),
                // B9: la conexión todavía no está vinculada (eso pasa al
                // verificarla), así que no aparece en "Canales". Nunca se
                // muestran credenciales.
                Section::make('WhatsApp del negocio — pendiente de verificación')
                    ->components([
                        TextEntry::make('pending_business_phone_number')
                            ->label('Número')
                            ->state(fn ($record) => Channel::pendingBusinessFor($record)->value('phone_number')),
                        TextEntry::make('pending_business_last_error')
                            ->label('Último intento de verificación')
                            ->state(fn ($record) => Channel::pendingBusinessFor($record)->first()?->metadata['last_verification_error'] ?? 'Todavía no se verificó.'),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => Channel::pendingBusinessFor($record)->exists()),
            ]);
    }
}
