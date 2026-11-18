<?php

use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Http;

it('returns the server-side estimate as exact decimal strings', function () {
    fakePixelforgePricing();

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            // Blank reference fields are not counted.
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png', ''],
        ])
        ->assertOk()
        ->assertJsonPath('estimate.provider.max', '0.373875')
        ->assertJsonPath('estimate.customer.max', '0.46734375')
        ->assertJsonPath('estimate.customer.expected', '0.4125625')
        ->assertJsonPath('estimate.lines.0.type', 'input_text_tokens')
        ->assertJsonPath('estimate.lines.0.tokens.expected', '100')
        ->assertJsonPath('estimate.lines.1.tokens.expected', '1530');
});

it('caches Pixelforge pricing between estimates', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();

    foreach (['a', 'ab', 'abc'] as $prompt) {
        $this->actingAs($user)
            ->postJson(route('generations.estimate'), ['prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'low'])
            ->assertOk();
    }

    Http::assertSentCount(1);
});

it('answers 503 instead of inventing a price when pricing is unavailable', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(null, 500)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertServiceUnavailable()
        ->assertJsonMissingPath('estimate');
});

it('rejects pricing data that does not match the documented shape', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(['currency' => 'eur', 'unit' => 'per_1m_tokens'])]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertServiceUnavailable();
});
