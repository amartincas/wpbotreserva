<?php

namespace App\Filament\Pages;

use App\Models\BotMessage;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Textos del bot (RegistroNegocioAgent, GestionNegocioAgent,
 * ServiceResourceSelectionFlow y los extractores de campo) editables desde
 * acá — Fase 2 del plan de mejoras post-piloto. Global (un solo set, sin
 * multi-tenant), mismo criterio de acceso que WhatsAppPlatformSettingsPage.
 *
 * Los campos se agrupan por el prefijo de la key ("registro.nombre_negocio"
 * → grupo "registro") — es el mismo prefijo que Filament usa como statePath
 * anidado (un Textarea llamado "registro.nombre_negocio" anida solo en
 * $data['registro']['nombre_negocio']), así que agrupar visualmente por ese
 * mismo prefijo evita cualquier mapeo aparte entre "cómo se guarda" y "cómo
 * se muestra".
 *
 * Solo edita valores de claves YA existentes — el conjunto de claves
 * válidas lo define el código que las consume (BotMessageRepository), no
 * este panel. Agregar una clave nueva requiere una migración (mismo patrón
 * incremental que el resto del proyecto).
 */
class BotMessagesPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Mensajes del bot';

    protected static ?string $title = 'Mensajes del bot';

    protected static ?string $slug = 'bot-messages';

    protected string $view = 'filament.pages.bot-messages-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->is_super_admin;
    }

    public function mount(): void
    {
        $data = [];

        foreach (BotMessage::all() as $message) {
            [$prefix, $field] = explode('.', $message->key, 2);
            $data[$prefix][$field] = $message->template;
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $sections = BotMessage::orderBy('key')->get()
            ->groupBy(fn (BotMessage $message) => explode('.', $message->key, 2)[0])
            ->map(function ($messages, string $prefix) {
                $fields = $messages->map(function (BotMessage $message) {
                    [$prefix, $field] = explode('.', $message->key, 2);

                    return Textarea::make("{$prefix}.{$field}")
                        ->label($message->key)
                        ->helperText($message->description)
                        ->rows(3)
                        ->required()
                        ->columnSpanFull();
                })->all();

                return Section::make(ucfirst($prefix))->components($fields);
            })
            ->values()
            ->all();

        return $schema->statePath('data')->components($sections);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (BotMessage::all() as $message) {
            [$prefix, $field] = explode('.', $message->key, 2);
            $template = $state[$prefix][$field] ?? null;

            if ($template !== null && $template !== $message->template) {
                $message->update(['template' => $template]);
            }
        }

        Notification::make()
            ->title('Mensajes guardados')
            ->success()
            ->send();
    }
}
