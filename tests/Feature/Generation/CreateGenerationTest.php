<?php

use App\Domain\Billing\Services\CreditLedger;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationForm(array $overrides = []): array
{
    return array_filter([
        'client_request_id' => (string) Str::uuid(),
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        ...$overrides,
    ], fn (mixed $value): bool => $value !== null);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    app(CreditLedger::class)->purchase($this->user->id, BigDecimal::of('10.00'), (string) Str::uuid());
});

it('saves the generation and its server-side quote before queueing the submission', function () {
    fakePixelforgePricing();
    $references = ['https://example.com/a.png', 'https://example.com/b.png'];

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm([
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => $references,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('generations.index'));

    $generation = Generation::sole();

    expect($generation->status)->toBe(GenerationStatus::PENDING)
        ->and($generation->user_id)->toBe($this->user->id)
        ->and(Str::isUuid($generation->idempotency_key))->toBeTrue()
        ->and($generation->provider_request)->toBe([
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => $references,
            'metadata' => ['generation_id' => $generation->id],
        ])
        // 100 text + 1530 image + 6450 output + 4500 (midpoint) reasoning tokens.
        ->and(Money::toStorage($generation->estimated_provider_cost_usd))->toBe('0.330050000000')
        ->and(Money::toStorage($generation->estimated_customer_cost_usd))->toBe('0.412562500000')
        // The docs' worked maximum (0.373875) plus the markup.
        ->and(Money::toStorage($generation->max_customer_cost_usd))->toBe('0.467343750000')
        ->and($generation->actual_customer_cost_usd)->toBeNull();

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $generation->id);
});

it('ignores any price sent by the browser', function () {
    fakePixelforgePricing();

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm(['estimated_cost' => '0.00']))
        ->assertSessionHasErrors('estimated_cost');

    expect(Generation::count())->toBe(0);
});

it('turns a double-submitted form into a single generation', function () {
    fakePixelforgePricing();
    $form = generationForm();

    $this->actingAs($this->user)->post(route('generations.store'), $form);
    $this->actingAs($this->user)->post(route('generations.store'), $form);

    expect(Generation::count())->toBe(1);
    Queue::assertPushed(SubmitGenerationJob::class, 1);
});

it('validates requests the way Pixelforge would', function (array $overrides, string $field) {
    fakePixelforgePricing();

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm($overrides))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'whitespace-only prompt' => [['prompt' => "   \n "], 'prompt'],
    'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'Unicode prompt over 4,000 characters' => [['prompt' => str_repeat('🦊', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'non-http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'reference that is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'unknown field' => [['seed' => '42'], 'seed'],
    'missing client request id' => [['client_request_id' => null], 'client_request_id'],
]);

it('accepts a prompt of exactly 4,000 Unicode characters', function () {
    fakePixelforgePricing();

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm(['prompt' => str_repeat('🦊', 4000)]))
        ->assertSessionHasNoErrors();

    expect(Generation::sole()->prompt)->toBe(str_repeat('🦊', 4000));
});

it('refuses to queue anything when pricing is unavailable', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(null, 503)]);

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm())
        ->assertSessionHasErrors('pricing');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('caps how many generations one user can create per UTC day', function () {
    fakePixelforgePricing();
    config(['services.pixelforge.limits.per_user_per_day' => 2]);

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($this->user)->post(route('generations.store'), generationForm())->assertSessionHasNoErrors();
    }

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm())
        ->assertSessionHasErrors(['limit' => "You've reached today's limit of 2 generations. It resets at midnight UTC."]);

    expect(Generation::count())->toBe(2);
    Queue::assertPushed(SubmitGenerationJob::class, 2);

    // A new UTC day starts a new count.
    $this->travelTo(now()->utc()->addDay()->startOfDay()->addMinute());
    $this->actingAs($this->user)->post(route('generations.store'), generationForm())->assertSessionHasNoErrors();

    expect(Generation::count())->toBe(3);
});

it('caps how many generations the whole demo creates per UTC day', function () {
    fakePixelforgePricing();
    config(['services.pixelforge.limits.per_day' => 1]);
    Generation::factory()->create(); // another user's, today

    $this->actingAs($this->user)
        ->post(route('generations.store'), generationForm())
        ->assertSessionHasErrors(['limit' => 'This demo has reached its generation limit for today. It resets at midnight UTC.']);

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('still answers a resent form with its generation once the limit is reached', function () {
    fakePixelforgePricing();
    config(['services.pixelforge.limits.per_user_per_day' => 1]);
    $form = generationForm();

    $this->actingAs($this->user)->post(route('generations.store'), $form)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('generations.store'), $form)->assertSessionHasNoErrors();

    expect(Generation::count())->toBe(1);
});
