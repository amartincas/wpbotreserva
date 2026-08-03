<?php

namespace App\Domain\Shared;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<PhoneNumber, PhoneNumber|string>
 */
class PhoneNumberCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PhoneNumber
    {
        return $value === null ? null : new PhoneNumber($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof PhoneNumber ? $value->value() : (new PhoneNumber($value))->value();
    }
}
