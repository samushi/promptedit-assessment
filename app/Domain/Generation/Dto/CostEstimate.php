<?php

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\TokenType;
use App\Support\Money;
use Brick\Math\BigDecimal;

/**
 * A pre-submit price estimate. Expected = most likely cost; min/max = the
 * range the final cost will fall in. Customer amounts include the markup.
 */
final readonly class CostEstimate
{
    /**
     * @param  array<string, array{min: int, expected: BigDecimal, max: int, expected_cost: BigDecimal}>  $tokens  keyed by TokenType value
     */
    public function __construct(
        public array $tokens,
        public TokenRates $rates,
        public BigDecimal $providerExpected,
        public BigDecimal $providerMin,
        public BigDecimal $providerMax,
        public BigDecimal $customerExpected,
        public BigDecimal $customerMin,
        public BigDecimal $customerMax,
    ) {}

    /**
     * JSON for the UI. Every amount is an exact decimal string.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lines = [];

        foreach (TokenType::cases() as $type) {
            $line = $this->tokens[$type->value];

            $lines[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'tokens' => [
                    'min' => $line['min'],
                    'expected' => Money::toWire($line['expected']),
                    'max' => $line['max'],
                ],
                'rate_per_million' => $this->rates->perMillion($type)->toString(),
                'expected_cost' => Money::toWire($line['expected_cost']),
            ];
        }

        return [
            'customer' => [
                'expected' => Money::toWire($this->customerExpected),
                'min' => Money::toWire($this->customerMin),
                'max' => Money::toWire($this->customerMax),
            ],
            'provider' => [
                'expected' => Money::toWire($this->providerExpected),
                'min' => Money::toWire($this->providerMin),
                'max' => Money::toWire($this->providerMax),
            ],
            'markup' => Money::toWire($this->customerExpected->minus($this->providerExpected)),
            'lines' => $lines,
        ];
    }
}
