<?php

declare(strict_types=1);

namespace App\Support\Casts;

use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a NUMERIC money column to a BigDecimal and back, never via float.
 *
 * Strict types are on so that assigning a float is a TypeError at runtime
 * instead of a silent conversion (Larastan flags it statically too).
 *
 * @implements CastsAttributes<BigDecimal|null, BigNumber|string|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        return $value === null ? null : Money::of((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BigNumber => Money::toStorage($value),
            default => Money::toStorage(Money::of($value)),
        };
    }
}
