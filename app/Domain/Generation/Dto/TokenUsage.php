<?php

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\TokenType;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;

/**
 * Actual token counts of a succeeded generation.
 */
final readonly class TokenUsage
{
    /**
     * @param  array<string, int>  $counts  keyed by TokenType value
     */
    private function __construct(private array $counts, public int $total) {}

    /**
     * @param  array<mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        $counts = [];

        foreach (TokenType::cases() as $type) {
            $count = $usage[$type->value] ?? null;

            if (! is_int($count) || $count < 0) {
                throw new InvalidPixelforgePayload("Usage [{$type->value}] must be a non-negative integer.");
            }

            $counts[$type->value] = $count;
        }

        $total = $usage['total_tokens'] ?? array_sum($counts);

        if (! is_int($total)) {
            throw new InvalidPixelforgePayload('Usage [total_tokens] must be an integer.');
        }

        return new self($counts, $total);
    }

    public function count(TokenType $type): int
    {
        return $this->counts[$type->value];
    }

    /** total_tokens is only a cross-check; billing always uses the four counts. */
    public function isConsistent(): bool
    {
        return array_sum($this->counts) === $this->total;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [...$this->counts, 'total_tokens' => $this->total];
    }
}
