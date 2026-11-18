<?php

use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pixelforge;

const CREATE_URL = 'pixelforge.test/v1/generations';

/**
 * @return list<string> the Idempotency-Key of every POST that was sent
 */
function sentIdempotencyKeys(): array
{
    return Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->all();
}

it('sends the stored request with bearer auth and its idempotency key, then records the acceptance', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/style.png']]);
    Http::fake([CREATE_URL => Http::response(Pixelforge::generationFor($generation, 'queued', ['id' => 'gen_ACCEPTED']), 202)]);

    runSubmitJob($generation)->assertNotReleased();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() === [
            'prompt' => $generation->prompt,
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => $generation->id],
        ]);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->provider_generation_id)->toBe('gen_ACCEPTED')
        ->and($generation->rates)->toBe(Pixelforge::RATES)
        ->and($generation->accepted_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z')
        ->and($generation->submission_attempts)->toBe(1)
        ->and($generation->actual_customer_cost_usd)->toBeNull();
});

it('rejects at $0, without retrying, when Pixelforge definitively refuses', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error($code, 'Refused.'), $status)]);

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::REJECTED)
        ->and($generation->error_code)->toBe($code)
        ->and(Money::toStorage($generation->actual_provider_cost_usd))->toBe('0.000000000000')
        ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.000000000000');

    Http::assertSentCount(1);
})->with([
    '[blocked] content policy' => [400, 'content_policy_violation'],
    'validation' => [422, 'validation_failed'],
    'invalid API key' => [401, 'invalid_api_key'],
    'idempotency key reused' => [409, 'idempotency_key_reused'],
    'daily quota' => [429, 'daily_limit_exceeded'],
]);

it('shows Pixelforge\'s reason for a validation failure', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('validation_failed', 'The selected size is invalid.'), 422)]);

    runSubmitJob($generation);

    expect($generation->refresh()->error_message)->toBe('Pixelforge rejected the request: The selected size is invalid.');
});

it('retries an overload with the same key, honouring Retry-After and backing off', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('overloaded'), 503, ['Retry-After' => '2'])]);

    runSubmitJob($generation, attempt: 1)->assertReleased(delay: 2);
    runSubmitJob($generation, attempt: 2)->assertReleased(delay: 4);
    runSubmitJob($generation, attempt: 3)->assertReleased(delay: 8);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::PENDING)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->submission_ambiguous)->toBeFalse()
        ->and(array_unique(sentIdempotencyKeys()))->toBe([$generation->idempotency_key]);
});

it('waits as long as the rate limiter asks, even beyond the backoff cap', function (string $retryAfter) {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('rate_limit_exceeded'), 429, ['Retry-After' => $retryAfter])]);

    runSubmitJob($generation)->assertReleased(delay: (int) $retryAfter);
})->with(['37', '90']);

it('waits at most the managed queue\'s 15 minutes, then simply asks again', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('rate_limit_exceeded'), 429, ['Retry-After' => '3600'])]);

    runSubmitJob($generation)->assertReleased(delay: SubmitGenerationAction::MAX_QUEUE_DELAY_SECONDS);

    expect($generation->fresh()->status)->toBe(GenerationStatus::PENDING);
});

it('stops after a bounded number of failures and rejects at $0 when nothing was created', function () {
    $generation = Generation::factory()->create(['submission_failures' => SubmitGenerationAction::MAX_FAILURES - 1]);
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('overloaded'), 503, ['Retry-After' => '2'])]);

    runSubmitJob($generation, attempt: SubmitGenerationAction::MAX_FAILURES)->assertNotReleased();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::REJECTED)
        ->and($generation->error_code)->toBe('overloaded')
        ->and(Money::toWire($generation->actual_customer_cost_usd))->toBe('0');
});

it('does not spend the retry budget while waiting for a concurrency slot', function () {
    $generation = Generation::factory()->create();
    $responses = Http::sequence();
    foreach (range(1, SubmitGenerationAction::MAX_FAILURES + 2) as $ignored) {
        $responses->push(Pixelforge::error('concurrency_limit_exceeded'), 429, ['Retry-After' => '5']);
    }
    $responses->push(Pixelforge::error('overloaded'), 503, ['Retry-After' => '2']);
    Http::fake([CREATE_URL => $responses]);

    $job = new SubmitGenerationJob($generation->id);
    foreach (range(1, SubmitGenerationAction::MAX_FAILURES + 2) as $attempt) {
        runSubmitJob($generation, attempt: $attempt, job: $job)->assertReleased();
    }

    // The first real failure after many slot waits is retried, not rejected.
    runSubmitJob($generation, attempt: SubmitGenerationAction::MAX_FAILURES + 3, job: $job)->assertReleased(delay: 2);

    expect($generation->refresh()->status)->toBe(GenerationStatus::PENDING)
        ->and($generation->submission_failures)->toBe(1)
        ->and($generation->submission_attempts)->toBe(SubmitGenerationAction::MAX_FAILURES + 3);
});

it('keeps waiting for a concurrency slot for minutes, then gives up', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(Pixelforge::error('concurrency_limit_exceeded'), 429, ['Retry-After' => '5'])]);

    runSubmitJob($generation, attempt: 1)->assertReleased(delay: 5);
    // Far beyond the attempt budget of other errors, still waiting for a slot.
    $job = new SubmitGenerationJob($generation->id);
    runSubmitJob($generation, attempt: 15, job: $job)->assertReleased(delay: 60);

    $this->travel(SubmitGenerationAction::CONCURRENCY_WAIT_SECONDS + 1)->seconds();
    runSubmitJob($generation, attempt: 16, job: $job)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::REJECTED)
        ->and($generation->error_code)->toBe('concurrency_limit_exceeded');
});

it('retries a timed-out POST with the same key and body, creating only one generation', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::sequence()
        ->pushFailedConnection('Operation timed out')
        ->push(Pixelforge::generationFor($generation, 'queued', ['id' => 'gen_ONLY_ONE']), 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmitJob($generation, attempt: 1)->assertReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::PENDING)
        ->and($generation->submission_ambiguous)->toBeTrue()
        ->and($generation->error_code)->toBe('submission_unconfirmed');

    runSubmitJob($generation, attempt: 2)->assertNotReleased();

    $generation->refresh();
    $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->unique();

    expect($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->provider_generation_id)->toBe('gen_ONLY_ONE')
        ->and($generation->error_code)->toBeNull()
        ->and(sentIdempotencyKeys())->toBe([$generation->idempotency_key, $generation->idempotency_key])
        ->and($bodies)->toHaveCount(1)
        ->and(Generation::count())->toBe(1);
});

it('never rejects a submission whose outcome is unknown', function (mixed $response) {
    $generation = Generation::factory()->create(['submission_failures' => SubmitGenerationAction::MAX_FAILURES - 1]);
    Http::fake([CREATE_URL => $response]);

    runSubmitJob($generation, attempt: SubmitGenerationAction::MAX_FAILURES)->assertNotReleased();

    $generation->refresh();

    // Left pending for the reconciler, which resends it with the same key.
    expect($generation->status)->toBe(GenerationStatus::PENDING)
        ->and($generation->submission_ambiguous)->toBeTrue()
        ->and($generation->actual_customer_cost_usd)->toBeNull();
})->with([
    'timeout' => fn () => Http::failedConnection(),
    'bare 500' => fn () => Http::response('<html>Bad gateway</html>', 502),
    'unreadable 202' => fn () => Http::response(['id' => 'nope'], 202),
]);

it('settles a replayed response that is already final', function () {
    $generation = Generation::factory()->create();
    Http::fake([CREATE_URL => Http::response(
        Pixelforge::generationFor($generation, 'succeeded', ['id' => 'gen_DONE']),
        202,
        ['Idempotent-Replayed' => 'true'],
    )]);

    runSubmitJob($generation);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and(Money::toStorage($generation->actual_provider_cost_usd))->toBe('0.305510000000')
        ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000');
});

it('does not resubmit a generation that is no longer pending', function () {
    $generation = Generation::factory()->processing()->create();
    Http::fake();

    runSubmitJob($generation);

    Http::assertNothingSent();
});
