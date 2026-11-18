<?php

use App\Domain\Generation\Actions\RecoverGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\ProcessPixelforgeEventJob;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

it('polls quiet in-flight generations and finalises them without any webhook', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::generationFor($generation, 'succeeded'))]);

    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000');

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/generations/gen_A'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

it('leaves generations alone while webhooks keep them up to date', function () {
    Generation::factory()->processing('gen_A')->create();
    Http::fake();

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('cannot bill twice when a poll and a webhook both report the success', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    $succeeded = Pixelforge::generationFor($generation, 'succeeded');

    // The webhook lands while our GET is in flight; the GET then applies the same result.
    Http::fake(['pixelforge.test/v1/generations/gen_A' => function () use ($succeeded) {
        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', $succeeded))->assertNoContent();

        return Http::response($succeeded);
    }]);

    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000');
});

it('skips a tick instead of hammering Pixelforge when polling is rate limited', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::error('rate_limit_exceeded'), 429, ['Retry-After' => '30'])]);

    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
    expect($generation->refresh()->status)->toBe(GenerationStatus::PROCESSING);
});

it('resubmits a stalled pending generation with its original key', function () {
    Queue::fake();
    $generation = Generation::factory()->create(['submission_ambiguous' => true, 'error_code' => 'submission_unconfirmed']);

    $this->travel(RecoverGenerationAction::RESUBMIT_AFTER_SECONDS + 1)->seconds();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $generation->id);
});

it('gives up on a submission Pixelforge never confirmed within a day, at $0', function () {
    Queue::fake();
    $generation = Generation::factory()->create(['submission_ambiguous' => true]);

    $this->travel(RecoverGenerationAction::GIVE_UP_AFTER_HOURS)->hours();
    $this->travel(1)->minute();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::REJECTED)
        ->and($generation->error_code)->toBe('submission_unconfirmed')
        ->and(Money::toWire($generation->actual_customer_cost_usd))->toBe('0');

    Queue::assertNotPushed(SubmitGenerationJob::class);
});

it('re-dispatches stored webhook events whose processing never finished', function () {
    Queue::fake();
    PixelforgeWebhookEvent::query()->insert([
        'event_id' => 'evt_STUCK',
        'type' => 'generation.succeeded',
        'payload' => json_encode(Pixelforge::event('generation.succeeded', Pixelforge::generation('succeeded'), 'evt_STUCK')),
        'received_at' => now()->subMinutes(10),
    ]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(ProcessPixelforgeEventJob::class, fn (ProcessPixelforgeEventJob $job) => $job->eventId === 'evt_STUCK');
});

it('is scheduled every minute outside production', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('* * * * *  php artisan pixelforge:reconcile')
        ->assertSuccessful();
});

it('is not scheduled in production, so a scale-to-zero app can sleep', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('schedule:list')->doesntExpectOutputToContain('pixelforge:reconcile')->assertSuccessful();
});

it('runs on an explicitly configured schedule, even in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['services.pixelforge.reconcile_schedule' => '0 * * * *']);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('0 * * * *  php artisan pixelforge:reconcile')
        ->assertSuccessful();
});

it('can be switched off locally', function () {
    config(['services.pixelforge.reconcile_schedule' => false]);

    $this->artisan('schedule:list')->doesntExpectOutputToContain('pixelforge:reconcile')->assertSuccessful();
});
