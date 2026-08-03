<?php

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Value Object compartido entre contextos (Tenancy, CRM, Conversational) —
 * validación de formato E.164 únicamente, sin comportamiento de negocio.
 * Inmutable, comparado por valor.
 */
final class PhoneNumber
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $trimmed)) {
            throw new InvalidArgumentException("«{$value}» no es un número de teléfono E.164 válido (ej. +573001234567).");
        }

        $this->value = $trimmed;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
