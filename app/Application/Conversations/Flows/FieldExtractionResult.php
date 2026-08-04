<?php

namespace App\Application\Conversations\Flows;

/**
 * Result DTO (mismo patrón que OrganizationResolution) — nunca una
 * excepción para "el usuario respondió algo no interpretable", que es un
 * caso esperado, no excepcional.
 */
final class FieldExtractionResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly mixed $value = null,
        public readonly ?string $reason = null,
    ) {}

    public static function success(mixed $value): self
    {
        return new self(successful: true, value: $value);
    }

    public static function failure(string $reason): self
    {
        return new self(successful: false, reason: $reason);
    }
}
