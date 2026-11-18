<?php

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps guests out', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
    $this->post(route('generations.store'))->assertRedirect(route('login'));
    $this->postJson(route('generations.estimate'))->assertUnauthorized();
});

it('shows each user only their own generations', function () {
    fakePixelforgePricing();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $mine = Generation::factory()->for($alice)->create(['prompt' => 'Alice\'s lighthouse']);
    Generation::factory()->for($bob)->create(['prompt' => 'Bob\'s castle']);

    $this->actingAs($alice)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $mine->id)
            ->where('generations.data.0.prompt', 'Alice\'s lighthouse')
            ->where('pricingAvailable', true)
            ->has('options.sizes', 3)
            ->has('options.qualities', 3));
});

it('shows estimated and exact actual costs as strings, and failures as free', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    Generation::factory()->for($user)->processing()->create([
        'status' => GenerationStatus::SUCCEEDED,
        'actual_provider_cost_usd' => BigDecimal::of('0.30551'),
        'actual_customer_cost_usd' => BigDecimal::of('0.3818875'),
        'image_url' => 'https://pixelforge.promptedit.dev/images/a.png',
        'image_url_expires_at' => now()->addHours(20),
        'completed_at' => now(),
        'created_at' => now()->subMinute(),
    ]);
    Generation::factory()->for($user)->processing()->create([
        'status' => GenerationStatus::FAILED,
        'actual_provider_cost_usd' => BigDecimal::zero(),
        'actual_customer_cost_usd' => BigDecimal::zero(),
        'error_code' => 'generation_failed',
        'error_message' => 'The model couldn\'t produce an image.',
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'failed')
            ->where('generations.data.0.actual_cost', '0')
            ->where('generations.data.0.error_code', 'generation_failed')
            ->where('generations.data.1.status', 'succeeded')
            ->where('generations.data.1.actual_cost', '0.3818875')
            ->where('generations.data.1.estimated_cost', '0.07288125')
            ->where('generations.data.1.image_expired', false)
            ->missing('generations.data.1.actual_provider_cost_usd'));
});

it('marks expired images instead of sending a dead link', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    Generation::factory()->for($user)->processing()->create([
        'status' => GenerationStatus::SUCCEEDED,
        'actual_provider_cost_usd' => BigDecimal::of('0.30551'),
        'actual_customer_cost_usd' => BigDecimal::of('0.3818875'),
        'image_url' => 'https://pixelforge.promptedit.dev/images/old.png',
        'image_url_expires_at' => now()->subHour(),
        'completed_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
});

it('tells the user when pricing is unavailable instead of inventing a price', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(null, 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pricingAvailable', false));
});

it('never sends Pixelforge credentials to the browser', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertDontSee('pf_test_key')
        ->assertDontSee('whsec_test_secret')
        ->assertDontSee('idempotency_key');
});
