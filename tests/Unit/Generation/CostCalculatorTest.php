<?php

use App\Domain\Generation\Dto\TokenRates;
use App\Domain\Generation\Dto\TokenUsage;
use App\Domain\Generation\Services\CostCalculator;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Tests\Support\Pixelforge;

function usage(int $text, int $image, int $output, int $reasoning): TokenUsage
{
    return TokenUsage::fromArray([
        'input_text_tokens' => $text,
        'input_image_tokens' => $image,
        'output_tokens' => $output,
        'reasoning_tokens' => $reasoning,
        'total_tokens' => $text + $image + $output + $reasoning,
    ]);
}

it('prices the golden example from the Pixelforge docs exactly', function () {
    $calculator = new CostCalculator;
    $rates = TokenRates::fromArray(Pixelforge::RATES);

    $provider = $calculator->providerCost(usage(12, 765, 6450, 3184), $rates);
    $customer = $calculator->customerPrice($provider);

    expect($calculator->tokenCost(12, BigDecimal::of('5.00'))->isEqualTo('0.000060000'))->toBeTrue()
        ->and($calculator->tokenCost(765, BigDecimal::of('10.00'))->isEqualTo('0.007650000'))->toBeTrue()
        ->and($calculator->tokenCost(6450, BigDecimal::of('40.00'))->isEqualTo('0.258000000'))->toBeTrue()
        ->and($calculator->tokenCost(3184, BigDecimal::of('12.50'))->isEqualTo('0.039800000'))->toBeTrue()
        ->and(Money::toStorage($provider))->toBe('0.305510000000')
        ->and(Money::toStorage($customer))->toBe('0.381887500000')
        ->and(Money::toStorage($customer->minus($provider)))->toBe('0.076377500000');
});

it('bills every token type at its own rate, never total_tokens at one rate', function () {
    $calculator = new CostCalculator;

    $cost = $calculator->providerCost(usage(0, 0, 0, 1_000_000), TokenRates::fromArray(Pixelforge::RATES));

    expect(Money::toWire($cost))->toBe('12.5');
});

it('keeps fractions of a cent exact with no floating-point drift', function () {
    $calculator = new CostCalculator;
    $rates = TokenRates::fromArray(Pixelforge::RATES);

    // One token of each type: 5 + 10 + 40 + 12.5 = 67.5 USD per 1M tokens.
    $provider = $calculator->providerCost(usage(1, 1, 1, 1), $rates);

    expect(Money::toStorage($provider))->toBe('0.000067500000')
        ->and(Money::toStorage($calculator->customerPrice($provider)))->toBe('0.000084375000');

    // Summing many tiny amounts stays exact (0.1 + 0.2 style drift is impossible).
    $total = BigDecimal::zero();
    foreach (range(1, 1000) as $ignored) {
        $total = $total->plus($calculator->customerPrice($provider));
    }

    expect(Money::toWire($total))->toBe('0.084375');
});

it('applies a 25% markup, not a 25% margin', function () {
    $calculator = new CostCalculator;

    $price = $calculator->customerPrice(BigDecimal::of('1.00'));

    expect($price->isEqualTo('1.25'))->toBeTrue()
        // A 25% margin would have been 1.3333...
        ->and($price->isEqualTo('1.3333'))->toBeFalse();
});

it('needs at most 11 decimal places for any marked-up 9-decimal provider cost', function () {
    $calculator = new CostCalculator;

    $price = $calculator->customerPrice(BigDecimal::of('0.000000001'));

    expect(Money::toStorage($price))->toBe('0.000000001250');
});

it('refuses to store an amount that would need rounding', function () {
    Money::toStorage(BigDecimal::of('0.0000000000001'));
})->throws(RoundingNecessaryException::class);

it('parses only plain decimal strings as money', function (string $input) {
    Money::of($input);
})->with(['1e-3', '0.1.2', '', 'abc', ' 1.00'])->throws(InvalidArgumentException::class);
