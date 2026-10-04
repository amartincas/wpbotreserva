<?php

namespace App\Application\Contracts;

use App\Application\Channels\PhoneNumberVerification;
use App\Domain\Tenancy\Channel;

/**
 * B9 — confirma contra Meta que los datos cargados de un Channel BUSINESS
 * son correctos: el phone_number_id existe y es accesible con su token, su
 * número coincide con el cargado, y pertenece a la WABA indicada. Nunca
 * modifica el Channel — eso lo decide VerifyBusinessChannelCommand.
 */
interface MetaPhoneNumberVerifierInterface
{
    public function verify(Channel $channel): PhoneNumberVerification;
}
