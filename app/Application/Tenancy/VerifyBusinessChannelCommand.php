<?php

namespace App\Application\Tenancy;

use App\Application\Channels\PhoneNumberVerification;
use App\Application\Contracts\MetaPhoneNumberVerifierInterface;
use App\Application\Exceptions\BusinessChannelConnectionException;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B9 — segundo paso de la conexión de un WhatsApp de negocio: verifica el
 * Channel PENDING contra Meta y, SOLO si Meta confirma los datos, en una
 * única transacción lo vincula a su Organization y lo pasa a ACTIVE (el
 * vínculo va primero: si las invariantes de ChannelOrganization lo
 * rechazan, el status nunca cambia).
 *
 * Si la verificación falla, el Channel queda exactamente como estaba —
 * PENDING_VERIFICATION, sin vínculo — y el motivo se guarda en metadata y
 * se loguea (nunca el token), para que el super-admin lo corrija y vuelva a
 * verificar.
 */
class VerifyBusinessChannelCommand
{
    public function __construct(private readonly MetaPhoneNumberVerifierInterface $verifier) {}

    public function handle(Channel $channel): PhoneNumberVerification
    {
        $organizationId = $channel->metadata['pending_organization_id'] ?? null;

        if (! $channel->isBusiness() || $channel->status !== ChannelStatus::PENDING_VERIFICATION || $organizationId === null) {
            throw new BusinessChannelConnectionException("El Channel #{$channel->id} no es una conexión de negocio pendiente de verificación.");
        }

        $verification = $this->verifier->verify($channel);

        if (! $verification->successful) {
            Log::warning('VerifyBusinessChannelCommand: Meta no confirmó el WhatsApp del negocio', [
                'channel_id' => $channel->id,
                'organization_id' => $organizationId,
                'phone_number_id' => $channel->phone_number_id,
                'reason' => $verification->reason,
            ]);

            $channel->update(['metadata' => [
                'pending_organization_id' => $organizationId,
                'last_verification_error' => $verification->reason,
                'last_verification_at' => now()->toIso8601String(),
            ]]);

            return $verification;
        }

        DB::transaction(function () use ($channel, $organizationId, $verification) {
            $locked = Channel::whereKey($channel->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ChannelStatus::PENDING_VERIFICATION) {
                throw new BusinessChannelConnectionException("El Channel #{$channel->id} cambió de estado mientras se verificaba.");
            }

            $locked->organizations()->attach(Organization::findOrFail($organizationId)->id, ['is_primary' => true]);

            $locked->update([
                'status' => ChannelStatus::ACTIVE,
                'metadata' => [
                    'verified_at' => now()->toIso8601String(),
                    'verified_display_phone_number' => $verification->displayPhoneNumber,
                    'verified_name' => $verification->verifiedName,
                ],
            ]);
        });

        $channel->refresh();

        return $verification;
    }
}
