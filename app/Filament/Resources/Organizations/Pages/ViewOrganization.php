<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Application\Exceptions\BusinessChannelConnectionException;
use App\Application\Tenancy\ConnectBusinessChannelCommand;
use App\Application\Tenancy\ConnectBusinessChannelData;
use App\Application\Tenancy\VerifyBusinessChannelCommand;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Domain\Tenancy\Exceptions\CentralChannelLinkException;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * B9 — único camino de alta del WhatsApp propio de un negocio (el resto del
 * panel sigue siendo de solo lectura). Dos pasos, solo para super-admin
 * (OrganizationResource::canViewAny):
 *
 *  1. "Conectar WhatsApp del negocio": carga número, phone_number_id, WABA y
 *     access token → Channel BUSINESS en PENDING_VERIFICATION, sin vínculo.
 *     Si ya hay una conexión pendiente, la misma acción corrige sus datos.
 *  2. "Verificar con Meta": consulta la Graph API; solo si Meta confirma,
 *     lo vincula a esta Organization y lo pasa a ACTIVE.
 *
 * El token se escribe en un campo de contraseña, nunca se vuelve a mostrar
 * (ni al corregir una conexión pendiente) y nunca se incluye en mensajes.
 */
class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectBusinessChannel')
                ->label(fn () => $this->pendingBusinessChannel() ? 'Corregir datos del WhatsApp' : 'Conectar WhatsApp del negocio')
                ->modalHeading('WhatsApp propio del negocio')
                ->modalDescription('Los datos salen de Meta Business (WhatsApp Manager). El número queda pendiente hasta verificarlo con Meta; recién ahí empieza a atender a los clientes.')
                ->visible(fn () => ! $this->record->channels()->exists())
                ->fillForm(fn () => $this->pendingBusinessChannel()?->only(['phone_number', 'phone_number_id', 'business_account_id']) ?? [])
                ->schema([
                    TextInput::make('phone_number')->label('Número de WhatsApp')->placeholder('+573001234567')->required(),
                    TextInput::make('phone_number_id')->label('Phone number ID (Meta)')->required(),
                    TextInput::make('business_account_id')->label('WABA ID')->required(),
                    TextInput::make('access_token')->label('Access token')->password()->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ConnectBusinessChannelCommand::class)->handle(new ConnectBusinessChannelData(
                            organization: $this->record,
                            phoneNumber: (string) $data['phone_number'],
                            phoneNumberId: trim((string) $data['phone_number_id']),
                            businessAccountId: trim((string) $data['business_account_id']),
                            accessToken: trim((string) $data['access_token']),
                        ));
                    } catch (BusinessChannelConnectionException|BusinessChannelAlreadyConnectedException|CentralChannelLinkException $e) {
                        Notification::make()->danger()->title('No se pudo conectar el WhatsApp')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('WhatsApp pendiente de verificación')
                        ->body('Ahora usá "Verificar con Meta" para activarlo.')
                        ->send();
                }),

            Action::make('verifyBusinessChannel')
                ->label('Verificar con Meta')
                ->visible(fn () => $this->pendingBusinessChannel() !== null)
                ->requiresConfirmation()
                ->modalDescription('Se consulta la Graph API de Meta con los datos cargados. Si coinciden, el número queda activo para los clientes de este negocio.')
                ->action(function (): void {
                    $channel = $this->pendingBusinessChannel();

                    if ($channel === null) {
                        return;
                    }

                    try {
                        $result = app(VerifyBusinessChannelCommand::class)->handle($channel);
                    } catch (BusinessChannelConnectionException|BusinessChannelAlreadyConnectedException|CentralChannelLinkException $e) {
                        Notification::make()->danger()->title('No se pudo verificar')->body($e->getMessage())->send();

                        return;
                    }

                    if (! $result->successful) {
                        Notification::make()->danger()->title('Meta no confirmó los datos')->body($result->reason)->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('WhatsApp del negocio activo')
                        ->body("{$result->displayPhoneNumber} ya atiende a los clientes de este negocio.")
                        ->send();
                }),
        ];
    }

    private function pendingBusinessChannel(): ?Channel
    {
        return Channel::pendingBusinessFor($this->record)->first();
    }
}
