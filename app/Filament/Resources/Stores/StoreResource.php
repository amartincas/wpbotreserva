<?php

namespace App\Filament\Resources\Stores;

use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\Pages\ViewStore;
use App\Filament\Resources\Stores\Schemas\StoreForm;
use App\Filament\Resources\Stores\Schemas\StoreInfolist;
use App\Filament\Resources\Stores\Tables\StoresTable;
use App\Models\Store;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static ?string $navigationLabel = 'Agencia';

    protected static ?string $modelLabel = 'Agencia';

    protected static ?string $pluralModelLabel = 'Agencias';

    // Un usuario de agencia (no superadmin) puede ver su propia agencia,
    // pero no editarla, crearla ni borrarla — esa configuración la maneja
    // solo el equipo de BoTravel.
    public static function canCreate(): bool
    {
        return Auth::user()?->is_super_admin ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->is_super_admin ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->is_super_admin ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->is_super_admin ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see all stores, regular users only see their own
        if (!Auth::user()?->is_super_admin) {
            $query->where('id', Auth::user()?->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return StoresTable::configure($table);
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return StoreForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'view' => ViewStore::route('/{record}'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
