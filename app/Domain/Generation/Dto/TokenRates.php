<?php

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\TokenType;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * USD per 1M tokens for each token type, as exact decimals ("12.50").
 */
final readonly class TokenRates
{
    /**
     * @param  array<string, BigDecimal>  $perMillion  keyed by TokenType value
     */
    private function __construct(private array $perMillion) {}

    /**
     * @param  array<mixed>  $rates  e.g. ["input_text_tokens" => "5.00", ...]
     */
    public static function fromArray(array $rates): self
    {
        $perMillion = [];

        foreach (TokenType::cases() as $type) {
            $rate = $rates[$type->value] ?? null;

            if (! is_string($rate) || preg_match('/^\d+(\.\d+)?$/', $rate) !== 1) {
                throw new InvalidPixelforgePayload("Rate [{$type->value}] must be a non-negative decimal string.");
            }

            try {
                $perMillion[$type->value] = BigDecimal::of($rate);
            } catch (MathException $e) {
                throw new InvalidPixelforgePayload("Rate [{$type->value}] is not a valid decimal.", previous: $e);
            }
        }

        return new self($perMillion);
    }

    public function perMillion(TokenType $type): BigDecimal
    {
        return $this->perMillion[$type->value];
    }

    /**
     * @return array<string, string> exactly as Pixelforge sent them
     */
    public function toArray(): array
    {
        return array_map(fn (BigDecimal $rate): string => $rate->toString(), $this->perMillion);
    }
}
