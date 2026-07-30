<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label('Nombre'),
                TextEntry::make('status')
                    ->label('Estado')
                    ->badge(),
                TextEntry::make('personality_type')
                    ->label('Tipo de Personalidad')
                    ->badge(),
                TextEntry::make('advisor_whatsapp')
                    ->label('WhatsApp del Asesor')
                    ->placeholder('No configurado'),
                TextEntry::make('system_prompt')
                    ->label('Prompt del Sistema')
                    ->columnSpanFull(),
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
