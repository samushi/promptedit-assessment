<?php

namespace App\Domain\Generation\Services;

use App\Domain\Generation\Dto\CostEstimate;
use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Dto\PricingConfig;
use App\Domain\Generation\Enums\TokenType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estimates a generation's cost before it is submitted, from Pixelforge's
 * published token rules:
 *
 *  - text tokens: ceil(characters / characters_per_text_token), and the real
 *    count is within +/- text_token_variance of that;
 *  - reference images and output tokens are exact;
 *  - reasoning tokens fall in a range set by quality. We assume the midpoint
 *    as the expected value and show the full range.
 *
 * "max" is the most the generation can possibly cost.
 */
final class CostEstimator
{
    public function __construct(private readonly CostCalculator $calculator) {}

    public function estimate(PricingConfig $pricing, CreateGenerationDto $request): CostEstimate
    {
        $ranges = $this->tokenRanges($pricing, $request);

        $tokens = [];
        $providerMin = $providerExpected = $providerMax = BigDecimal::zero();

        foreach (TokenType::cases() as $type) {
            $range = $ranges[$type->value];
            $rate = $pricing->rates->perMillion($type);
            $expectedCost = $this->calculator->tokenCost($range['expected'], $rate);

            $providerMin = $providerMin->plus($this->calculator->tokenCost($range['min'], $rate));
            $providerExpected = $providerExpected->plus($expectedCost);
            $providerMax = $providerMax->plus($this->calculator->tokenCost($range['max'], $rate));

            $tokens[$type->value] = [...$range, 'expected_cost' => $expectedCost];
        }

        return new CostEstimate(
            tokens: $tokens,
            rates: $pricing->rates,
            providerExpected: $providerExpected,
            providerMin: $providerMin,
            providerMax: $providerMax,
            customerExpected: $this->calculator->customerPrice($providerExpected),
            customerMin: $this->calculator->customerPrice($providerMin),
            customerMax: $this->calculator->customerPrice($providerMax),
        );
    }

    /**
     * @return array<string, array{min: int, expected: BigDecimal, max: int}>
     */
    private function tokenRanges(PricingConfig $pricing, CreateGenerationDto $request): array
    {
        $perToken = $pricing->charactersPerTextToken;
        $textTokens = intdiv($request->promptCharacters() + $perToken - 1, $perToken);
        $text = BigDecimal::of($textTokens);
        $variance = $pricing->textTokenVariance;

        $imageTokens = count($request->referenceImages()) * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens($request->quality(), $request->size());
        $reasoning = $pricing->reasoningTokens($request->quality());

        return [
            TokenType::INPUT_TEXT->value => [
                'min' => $text->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor)->toInt(),
                'expected' => $text,
                // Round up: the upper bound must never underestimate.
                'max' => $text->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt(),
            ],
            TokenType::INPUT_IMAGE->value => [
                'min' => $imageTokens,
                'expected' => BigDecimal::of($imageTokens),
                'max' => $imageTokens,
            ],
            TokenType::OUTPUT->value => [
                'min' => $outputTokens,
                'expected' => BigDecimal::of($outputTokens),
                'max' => $outputTokens,
            ],
            TokenType::REASONING->value => [
                'min' => $reasoning['min'],
                'expected' => BigDecimal::of($reasoning['min'] + $reasoning['max'])->dividedByExact(2),
                'max' => $reasoning['max'],
            ],
        ];
    }
}
