<?php

declare(strict_types=1);

namespace App\Domain\Reports\Dto;

use App\Domain\Generation\Enums\TokenType;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use Brick\Math\BigDecimal;

/**
 * One row of Pixelforge's usage report (or its total, which has no date).
 */
final readonly class ProviderUsageDay
{
    /**
     * @param  array<string, int>  $tokens  keyed by TokenType value
     */
    public function __construct(
        public ?string $date,
        public int $generations,
        public array $tokens,
        public BigDecimal $cost,
    ) {}

    /**
     * @param  array<mixed>  $row
     */
    public static function fromArray(array $row, bool $isTotal = false): self
    {
        $date = $row['date'] ?? null;

        if (! $isTotal && (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
            throw new InvalidPixelforgePayload('Usage day must have a YYYY-MM-DD date.');
        }

        // An exact decimal string with nine places, e.g. "0.169080000".
        $cost = $row['cost_usd'] ?? null;

        if (! is_string($cost) || preg_match('/^\d+(\.\d+)?$/', $cost) !== 1) {
            throw new InvalidPixelforgePayload('Usage cost_usd must be a decimal string.');
        }

        $tokens = [];

        foreach (TokenType::cases() as $type) {
            $count = $row[$type->value] ?? 0;
            $tokens[$type->value] = is_int($count) ? $count : 0;
        }

        $generations = $row['generations'] ?? 0;

        return new self(
            date: is_string($date) ? $date : null,
            generations: is_int($generations) ? $generations : 0,
            tokens: $tokens,
            cost: BigDecimal::of($cost),
        );
    }
}
