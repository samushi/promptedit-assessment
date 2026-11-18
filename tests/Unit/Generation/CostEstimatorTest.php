<?php

use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Dto\PricingConfig;
use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Domain\Generation\Services\CostCalculator;
use App\Domain\Generation\Services\CostEstimator;
use App\Support\Money;
use Tests\Support\Pixelforge;

function estimateFor(string $prompt, ImageSize $size = ImageSize::SQUARE, ImageQuality $quality = ImageQuality::MEDIUM, int $references = 0): array
{
    $request = new CreateGenerationDto($prompt, $size->value, $quality->value, array_fill(0, $references, 'https://example.com/style.png'));

    return (new CostEstimator(new CostCalculator))
        ->estimate(PricingConfig::fromArray(Pixelforge::pricing()), $request)
        ->toArray();
}

/**
 * @return array<string, mixed>
 */
function line(array $estimate, string $type): array
{
    return collect($estimate['lines'])->firstWhere('type', $type);
}

it('matches the "most a generation can cost" worked example from the docs', function () {
    $estimate = estimateFor(str_repeat('a', 400), ImageSize::LANDSCAPE, ImageQuality::HIGH, references: 2);

    expect(line($estimate, 'input_text_tokens')['tokens'])->toBe(['min' => 85, 'expected' => '100', 'max' => 115])
        ->and(line($estimate, 'input_image_tokens')['tokens']['max'])->toBe(1530)
        ->and(line($estimate, 'output_tokens')['tokens']['max'])->toBe(6450)
        ->and(line($estimate, 'reasoning_tokens')['tokens']['max'])->toBe(8000)
        ->and($estimate['provider']['max'])->toBe('0.373875')
        ->and($estimate['customer']['max'])->toBe('0.46734375');
});

it('counts Unicode characters, not bytes', function () {
    // 5 emoji are 20 bytes but 5 characters: ceil(5 / 4) = 2 tokens.
    $estimate = estimateFor('🦊🦊🦊🦊🦊');

    expect(line($estimate, 'input_text_tokens')['tokens']['expected'])->toBe('2');
});

it('charges exactly 765 input image tokens per reference image', function () {
    expect(line(estimateFor('x', references: 0), 'input_image_tokens')['tokens']['expected'])->toBe('0')
        ->and(line(estimateFor('x', references: 3), 'input_image_tokens')['tokens']['expected'])->toBe('2295')
        ->and(line(estimateFor('x', references: 3), 'input_image_tokens')['expected_cost'])->toBe('0.02295');
});

it('uses the exact output tokens for each quality and size', function (ImageQuality $quality, ImageSize $size, int $tokens) {
    expect(line(estimateFor('x', $size, $quality), 'output_tokens')['tokens'])
        ->toBe(['min' => $tokens, 'expected' => (string) $tokens, 'max' => $tokens]);
})->with([
    [ImageQuality::LOW, ImageSize::SQUARE, 280],
    [ImageQuality::LOW, ImageSize::PORTRAIT, 420],
    [ImageQuality::MEDIUM, ImageSize::SQUARE, 1100],
    [ImageQuality::MEDIUM, ImageSize::LANDSCAPE, 1650],
    [ImageQuality::HIGH, ImageSize::SQUARE, 4300],
    [ImageQuality::HIGH, ImageSize::PORTRAIT, 6450],
]);

it('assumes the midpoint of the reasoning range and reports the range', function () {
    expect(line(estimateFor('x', quality: ImageQuality::LOW), 'reasoning_tokens')['tokens'])->toBe(['min' => 0, 'expected' => '250', 'max' => 500])
        ->and(line(estimateFor('x', quality: ImageQuality::MEDIUM), 'reasoning_tokens')['tokens'])->toBe(['min' => 200, 'expected' => '1100', 'max' => 2000])
        ->and(line(estimateFor('x', quality: ImageQuality::HIGH), 'reasoning_tokens')['tokens'])->toBe(['min' => 1000, 'expected' => '4500', 'max' => 8000]);
});

it('adds the 25% markup to every estimate figure', function () {
    // 48 characters -> 12 text tokens; medium square; no references.
    $estimate = estimateFor(str_repeat('b', 48));

    // 12*5 + 1100*40 + 1100*12.5 = 57810 per 1M tokens.
    expect($estimate['provider']['expected'])->toBe('0.05781')
        ->and($estimate['customer']['expected'])->toBe('0.0722625')
        ->and($estimate['markup'])->toBe('0.0144525')
        ->and(Money::of($estimate['customer']['min'])->isLessThan(Money::of($estimate['customer']['expected'])))->toBeTrue()
        ->and(Money::of($estimate['customer']['max'])->isGreaterThan(Money::of($estimate['customer']['expected'])))->toBeTrue();
});

it('estimates an empty prompt as zero text tokens', function () {
    expect(line(estimateFor(''), 'input_text_tokens')['tokens'])->toBe(['min' => 0, 'expected' => '0', 'max' => 0]);
});
