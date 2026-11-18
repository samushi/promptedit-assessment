<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact USD amounts.
 *
 * Money never touches binary floating point: it is a brick/math BigDecimal
 * in PHP and a decimal string in the database (NUMERIC), JSON and the UI.
 */
final class Money
{
    /** Scale of the NUMERIC(24, 12) money columns. */
    public const int STORAGE_SCALE = 12;

    /**
     * Parse a plain decimal string such as "0.305510000".
     *
     * Floats, exponents and anything else are rejected rather than coerced.
     */
    public static function of(string $amount): BigDecimal
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Invalid decimal amount [{$amount}].");
        }

        return BigDecimal::of($amount);
    }

    /**
     * Format for a NUMERIC(24, 12) column.
     *
     * Throws instead of rounding if the amount needs more than 12 decimal places,
     * so precision can never be lost silently.
     */
    public static function toStorage(BigNumber $amount): string
    {
        return $amount->toBigDecimal()->toScale(self::STORAGE_SCALE, RoundingMode::Unnecessary)->toString();
    }

    /**
     * Canonical exact string for JSON/UI: no insignificant trailing zeros ("0.3818875", "0").
     */
    public static function toWire(BigNumber $amount): string
    {
        return $amount->toBigDecimal()->strippedOfTrailingZeros()->toString();
    }
}
