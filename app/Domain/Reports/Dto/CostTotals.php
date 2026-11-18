<?php

declare(strict_types=1);

namespace App\Domain\Reports\Dto;

use App\Domain\Generation\Enums\TokenType;
use App\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Billed generations, tokens and money for some slice of the report (a user
 * on a day, a whole day, the whole range). Exact decimals throughout.
 */
final readonly class CostTotals
{
    /**
     * @param  array<string, int>  $tokens  keyed by TokenType value
     */
    public function __construct(
        public int $generations,
        public BigDecimal $providerCost,
        public BigDecimal $charged,
        public array $tokens,
    ) {}

    public static function zero(): self
    {
        $tokens = [];

        foreach (TokenType::cases() as $type) {
            $tokens[$type->value] = 0;
        }

        return new self(0, BigDecimal::zero(), BigDecimal::zero(), $tokens);
    }

    public function plus(self $other): self
    {
        $tokens = $this->tokens;

        foreach ($other->tokens as $type => $count) {
            $tokens[$type] = ($tokens[$type] ?? 0) + $count;
        }

        return new self(
            $this->generations + $other->generations,
            $this->providerCost->plus($other->providerCost),
            $this->charged->plus($other->charged),
            $tokens,
        );
    }

    /** What we keep: what users paid minus what Pixelforge charged us. */
    public function profit(): BigDecimal
    {
        return $this->charged->minus($this->providerCost);
    }

    /**
     * @return array{generations: int, charged: string, provider_cost: string, profit: string, tokens: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'charged' => Money::toWire($this->charged),
            'provider_cost' => Money::toWire($this->providerCost),
            'profit' => Money::toWire($this->profit()),
            'tokens' => $this->tokens,
        ];
    }
}
