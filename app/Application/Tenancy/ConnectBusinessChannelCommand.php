<?php

namespace App\Application\Tenancy;

use App\Application\Exceptions\BusinessChannelConnectionException;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Exceptions\BusinessChannelAlreadyConnectedException;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B9 — primer paso para conectar el WhatsApp propio de un negocio: deja un
 * Channel BUSINESS en PENDING_VERIFICATION, todavía SIN vínculo con la
 * Organization (la Organization destino queda en metadata). El vínculo y el
 * paso a ACTIVE ocurren recién cuando Meta confirma los datos
 * (VerifyBusinessChannelCommand) — así un número mal cargado nunca llega a
 * atender clientes ni queda a medio activar.
 *
 * Reglas:
 *  - todos los campos son obligatorios; el número tiene que ser E.164;
 *  - la Organization no puede tener ya un Channel vinculado (B2: como
 *    máximo un BUSINESS por Organization);
 *  - el phone_number_id no puede pertenecer a otro Channel — en particular
 *    nunca al CENTRAL, que nunca se toca acá;
 *  - si la Organization ya tiene una conexión PENDIENTE, se reemplazan sus
 *    datos (corrige un dato mal cargado sin dejar Channels huérfanos).
 */
class ConnectBusinessChannelCommand
{
    public function handle(ConnectBusinessChannelData $data): Channel
    {
        $phoneNumber = $this->validated($data);

        return DB::transaction(function () use ($data, $phoneNumber) {
            if ($data->organization->channels()->exists()) {
                throw new BusinessChannelAlreadyConnectedException(
                    "La Organization #{$data->organization->id} ya tiene un WhatsApp conectado."
                );
            }

            $existing = Channel::where('phone_number_id', $data->phoneNumberId)->lockForUpdate()->first();
            $pending = Channel::pendingBusinessFor($data->organization)->lockForUpdate()->first();

            if ($existing !== null && ! $existing->is($pending)) {
                throw new BusinessChannelConnectionException($existing->isCentral()
                    ? 'Ese phone_number_id es el del número CENTRAL de WpbotReserva; no puede conectarse como WhatsApp de un negocio.'
                    : "Ese phone_number_id ya está cargado en otro Channel (#{$existing->id}).");
            }

            $channel = $pending ?? new Channel([
                'provider' => ChannelProvider::META_CLOUD_API,
                'channel_type' => ChannelType::WHATSAPP,
                'role' => ChannelRole::BUSINESS,
            ]);

            $channel->fill([
                'phone_number' => $phoneNumber->value(),
                'phone_number_id' => $data->phoneNumberId,
                'business_account_id' => $data->businessAccountId,
                'status' => ChannelStatus::PENDING_VERIFICATION,
                'credentials' => ['access_token' => $data->accessToken],
                'metadata' => ['pending_organization_id' => $data->organization->id],
            ]);
            $channel->save();

            return $channel;
        });
    }

    private function validated(ConnectBusinessChannelData $data): PhoneNumber
    {
        foreach ([
            'número de WhatsApp' => $data->phoneNumber,
            'phone_number_id' => $data->phoneNumberId,
            'WABA ID' => $data->businessAccountId,
            'access token' => $data->accessToken,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new BusinessChannelConnectionException("Falta el {$label}: las credenciales tienen que estar completas.");
            }
        }

        try {
            return new PhoneNumber(preg_replace('/[\s\-()]/', '', $data->phoneNumber));
        } catch (InvalidArgumentException) {
            throw new BusinessChannelConnectionException('El número de WhatsApp tiene que estar en formato internacional, por ejemplo +573001234567.');
        }
    }
}
