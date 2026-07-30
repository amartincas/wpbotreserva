<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('store_id')
                    ->relationship(
                        'store',
                        'name',
                        fn ($query) => $query
                            ->when(
                                !Auth::user()?->is_super_admin,
                                fn ($q) => $q->where('id', Auth::user()?->store_id)
                            )
                    )
                    ->required()
                    ->default(Auth::user()?->store_id),

                TextInput::make('id')
                    ->label('ID del Plan (para referencias de imágenes con IA)')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($record) => $record?->id ? "Usa [IMG:{$record->id}] para mostrar este plan" : '(el ID se asigna al crear)')
                    ->helperText('Referencia este ID en prompts o respuestas de la IA para mostrar imágenes del plan')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Nombre')
                    ->required(),

                Textarea::make('description')
                    ->label('Descripción')
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('price')
                    ->label('Precio de Venta')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->helperText('Precio que se cobra al cliente'),

                TextInput::make('cost_price')
                    ->label('Precio Costo')
                    ->numeric()
                    ->prefix('$')
                    ->helperText('Precio que se paga al operador/proveedor por este tour o producto'),

                ToggleButtons::make('type')
                    ->label('Tipo')
                    ->options([
                        'product' => 'Producto',
                        'service' => 'Servicio',
                    ])
                    ->default('product')
                    ->required()
                    ->inline(),

                TextInput::make('stock')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Disponibilidad (1 = Aceptando clientes, 0 = Cupo lleno)'
                            : 'Cantidad en Stock';
                    })
                    ->helperText(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Ingresa 1 si está aceptando nuevos clientes, 0 si el cupo está lleno'
                            : 'Ingresa el número de unidades disponibles';
                    }),

                Textarea::make('ai_sales_strategy')
                    ->label('Estrategia de Venta para la IA')
                    ->placeholder('¿Cómo debería la IA vender este plan?...')
                    ->columnSpanFull(),

                Textarea::make('faq_context')
                    ->label('FAQ y Contexto Operativo')
                    ->placeholder('Reglas, ciudades/destinos cubiertos, guías, preguntas frecuentes de este tour...')
                    ->columnSpanFull(),

                TextInput::make('required_customer_info')
                    ->label('Datos Obligatorios del Cliente')
                    ->placeholder('Ej: nombre completo, teléfono, punto de encuentro, fecha del tour...')
                    ->columnSpanFull(),

                TagsInput::make('meta_ad_ids')
                    ->label('IDs de anuncios de Meta (Click-to-WhatsApp)')
                    ->placeholder('Pega el ID del anuncio y presiona Enter')
                    ->helperText('Copia el ID del anuncio desde Meta Ads Manager (no el texto del mensaje). Así, cuando un cliente escriba desde ese anuncio, el sistema identifica el operador correcto aunque el mensaje prellenado no coincida exactamente con el nombre del producto.')
                    ->columnSpanFull(),

                // =====================================================
                // EXTRAS / ADICIONALES DEL PRODUCTO
                // =====================================================
                Repeater::make('extras')
                    ->label('Extras / Adicionales')
                    ->relationship('extras')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ej: Seguro de viaje')
                            ->required()
                            ->columnSpan(2),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->placeholder('Ej: Cobertura médica adicional durante el tour')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('sale_price')
                            ->label('Precio de Venta')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->helperText('Precio que se cobra al cliente'),

                        TextInput::make('cost_price')
                            ->label('Precio Costo')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->helperText('Precio que se paga al operador/proveedor'),

                        Toggle::make('is_available')
                            ->label('Disponible')
                            ->default(true)
                            ->columnSpanFull(),

                        TextInput::make('sort_order')
                            ->label('Orden')
                            ->numeric()
                            ->default(0)
                            ->helperText('Orden de presentación (menor número = primero)'),
                    ])
                    ->columns(2)
                    ->addActionLabel('+ Agregar extra')
                    ->reorderable('sort_order')
                    ->collapsible()
                    ->columnSpanFull(),

                // === GALERÍA DE IMÁGENES DEL PLAN ===
                FileUpload::make('images')
                    ->label('Imágenes del Plan')
                    ->multiple()
                    ->reorderable()
                    ->directory('products')
                    ->disk('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    ->helperText('Sube varias imágenes (JPG, PNG, WebP). La primera se usa como principal.')
                    ->columnSpanFull()
                    ->formatStateUsing(fn ($record) => $record?->images()->pluck('image_path')->toArray() ?? [])
                    ->dehydrated(false),
            ]);
    }
}
