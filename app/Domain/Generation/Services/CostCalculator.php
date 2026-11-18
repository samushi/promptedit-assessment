<?php

namespace App\Domain\Generation\Services;

use App\Domain\Generation\Dto\TokenRates;
use App\Domain\Generation\Dto\TokenUsage;
use App\Domain\Generation\Enums\TokenType;
use Brick\Math\BigDecimal;

/**
 * Exact pricing maths. No floats, no rounding: every result is the exact value.
 */
final class CostCalculator
{
    /** Users pay Pixelforge's cost plus a 25% markup (x1.25), not a 25% margin (/0.75). */
    public const string MARKUP_MULTIPLIER = '1.25';

    /**
     * Pixelforge's cost for actual usage: each of the four token types at its own
     * per-1M rate. total_tokens is never billed; it is only a cross-check.
     */
    public function providerCost(TokenUsage $usage, TokenRates $rates): BigDecimal
    {
        $cost = BigDecimal::zero();

        foreach (TokenType::cases() as $type) {
            $cost = $cost->plus($this->tokenCost($usage->count($type), $rates->perMillion($type)));
        }

        return $cost;
    }

    /**
     * tokens x (USD per 1M tokens) / 1,000,000. Dividing by 10^6 only moves the
     * decimal point, so the result is always exact.
     */
    public function tokenCost(BigDecimal|int $tokens, BigDecimal $ratePerMillion): BigDecimal
    {
        return $ratePerMillion->multipliedBy($tokens)->withPointMovedLeft(6);
    }

    /** What the user pays for a given Pixelforge cost. */
    public function customerPrice(BigDecimal $providerCost): BigDecimal
    {
        return $providerCost->multipliedBy(self::MARKUP_MULTIPLIER);
    }
}
