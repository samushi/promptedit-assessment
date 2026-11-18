<?php

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Pixelforge's public pricing (GET /v1/pricing): current rates plus the token
 * estimation parameters. Used only for estimates. Billing always uses the rates
 * snapshotted on each generation.
 */
final readonly class PricingConfig
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => exact output tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public BigDecimal $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /**
     * Build from the GET /v1/pricing body, validating everything we rely on.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['currency'] ?? null) !== 'usd' || ($data['unit'] ?? null) !== 'per_1m_tokens') {
            throw new InvalidPixelforgePayload('Pricing must be in usd per_1m_tokens.');
        }

        $tokens = self::arrayAt($data, 'tokens');
        $rates = TokenRates::fromArray(self::arrayAt($data, 'rates'));

        $charactersPerTextToken = self::intAt($tokens, 'characters_per_text_token');

        if ($charactersPerTextToken < 1) {
            throw new InvalidPixelforgePayload('characters_per_text_token must be positive.');
        }

        $outputTokens = [];
        $reasoningTokens = [];
        $output = self::arrayAt($tokens, 'output');
        $reasoning = self::arrayAt($tokens, 'reasoning');

        foreach (ImageQuality::cases() as $quality) {
            $sizes = self::arrayAt($output, $quality->value);

            foreach (ImageSize::cases() as $size) {
                $outputTokens[$quality->value][$size->value] = self::intAt($sizes, $size->value);
            }

            $range = self::arrayAt($reasoning, $quality->value);
            $min = self::intAt($range, 'min');
            $max = self::intAt($range, 'max');

            if ($min > $max) {
                throw new InvalidPixelforgePayload("Reasoning range for [{$quality->value}] is inverted.");
            }

            $reasoningTokens[$quality->value] = ['min' => $min, 'max' => $max];
        }

        return new self(
            rates: $rates,
            charactersPerTextToken: $charactersPerTextToken,
            textTokenVariance: self::variance($tokens['text_token_variance'] ?? null),
            tokensPerReferenceImage: self::intAt($tokens, 'per_reference_image'),
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokens(ImageQuality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * Same shape as the API response, so it can be cached and rebuilt with fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVariance->toString(),
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }

    /**
     * The variance arrives as a JSON number (0.15). Go through its shortest string
     * form so no binary-float artefacts reach the decimal maths.
     */
    private static function variance(mixed $value): BigDecimal
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidPixelforgePayload('text_token_variance must be a number.');
        }

        try {
            $variance = BigDecimal::of((string) $value);
        } catch (MathException $e) {
            throw new InvalidPixelforgePayload('text_token_variance is not a valid number.', previous: $e);
        }

        if ($variance->isNegative() || $variance->isGreaterThanOrEqualTo(1)) {
            throw new InvalidPixelforgePayload('text_token_variance must be between 0 and 1.');
        }

        return $variance;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidPixelforgePayload("Pricing field [{$key}] must be an object.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function intAt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidPixelforgePayload("Pricing field [{$key}] must be a non-negative integer.");
        }

        return $value;
    }
}
