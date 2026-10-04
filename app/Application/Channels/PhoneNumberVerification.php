<?php

namespace App\Application\Channels;

/**
 * Resultado de MetaPhoneNumberVerifierInterface::verify(). $reason es apto
 * para mostrarse al super-admin y para loguearse — nunca incluye el token.
 */
final class PhoneNumberVerification
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $reason = null,
        public readonly ?string $displayPhoneNumber = null,
        public readonly ?string $verifiedName = null,
    ) {}

    public static function verified(string $displayPhoneNumber, ?string $verifiedName): self
    {
        return new self(true, displayPhoneNumber: $displayPhoneNumber, verifiedName: $verifiedName);
    }

    public static function failed(string $reason): self
    {
        return new self(false, reason: $reason);
    }
}
