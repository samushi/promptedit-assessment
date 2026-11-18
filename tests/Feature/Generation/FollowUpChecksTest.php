<?php

use App\Domain\Generation\Actions\ApplyProviderSnapshotAction;
use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Domain\Generation\Dto\ProviderGeneration;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\ProcessPixelforgeEventJob;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Jobs\SyncGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Support\Money;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

// With no recurring scheduler in production, a generation whose webhooks are lost
// is recovered by a short ladder of checks queued when Pixelforge accepts it.

beforeEach(fn () => Queue::fake());

function acceptSnapshot(Generation $generation, string $status = 'queued'): void
{
    ApplyProviderSnapshotAction::run($generation, ProviderGeneration::fromArray(Pixelforge::generationFor($generation, $status)));
}

function runCheck(Generation $generation, ?int $followUp): SyncGenerationJob
{
    $job = new SyncGenerationJob($generation->id, $followUp);
    $job->handle();

    return $job;
}

it('queues the first check two minutes after Pixelforge accepts a generation', function () {
    $generation = Generation::factory()->create();

    acceptSnapshot($generation);

    Queue::assertPushed(SyncGenerationJob::class, 1);
    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->generationId === $generation->id
        && $job->followUp === 0
        && $job->delay === 120);
});

it('counts from acceptance, not creation, so a long wait for a slot is still covered', function () {
    $generation = Generation::factory()->create();

    // Waited ~9 minutes for one of Pixelforge's 3 concurrent slots before it was accepted.
    $this->travel(9)->minutes();
    acceptSnapshot($generation);

    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->followUp === 0 && $job->delay === 120);
    expect(SyncGenerationJob::FOLLOW_UPS)->toBe([120, 300, 600]);
});

it('starts the ladder once, however the acceptance is reported', function () {
    $generation = Generation::factory()->create();

    acceptSnapshot($generation, 'processing');   // an early webhook
    acceptSnapshot($generation->fresh(), 'queued');   // then the POST response
    acceptSnapshot($generation->fresh(), 'processing'); // and a duplicate delivery

    Queue::assertPushed(SyncGenerationJob::class, 1);
});

it('needs no checks when the first thing we hear is already final', function () {
    $generation = Generation::factory()->create();

    acceptSnapshot($generation, 'succeeded');

    Queue::assertNotPushed(SyncGenerationJob::class);
});

it('runs at 2, 5 and 10 minutes after acceptance while Pixelforge still reports it in progress', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::generationFor($generation, 'processing'))]);

    runCheck($generation, 0);
    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->followUp === 1 && $job->delay === 180);

    runCheck($generation, 1);
    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->followUp === 2 && $job->delay === 300);

    runCheck($generation, 2);

    // Bounded: nothing after the last rung.
    Queue::assertPushed(SyncGenerationJob::class, 2);
    Http::assertSentCount(3);
});

it('settles a generation whose webhooks were lost, then stops', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::generationFor($generation, 'succeeded'))]);

    runCheck($generation, 0);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000');
    Queue::assertNotPushed(SyncGenerationJob::class);
});

it('stops at once when webhooks already settled the generation', function () {
    $generation = Generation::factory()->processing('gen_A')->create(['status' => GenerationStatus::FAILED]);
    Http::fake();

    runCheck($generation, 0);

    Http::assertNothingSent();
    Queue::assertNotPushed(SyncGenerationJob::class);
});

it('keeps climbing the ladder when Pixelforge cannot be reached', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::error('rate_limit_exceeded'), 429, ['Retry-After' => '30'])]);

    runCheck($generation, 0);

    expect($generation->fresh()->status)->toBe(GenerationStatus::PROCESSING);
    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->followUp === 1);
});

it('queues the next rung while the current one still holds its unique lock', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::generationFor($generation, 'processing'))]);
    $current = new SyncGenerationJob($generation->id, 0);

    // A worker holds this lock until the job finishes.
    expect((new UniqueLock(app(Cache::class)))->acquire($current))->toBeTrue();

    $current->handle();

    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->followUp === 1);
});

it('never starts a ladder from a one-off check', function () {
    $generation = Generation::factory()->processing('gen_A')->create();
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(Pixelforge::generationFor($generation, 'processing'))]);

    runCheck($generation, null);

    Queue::assertNotPushed(SyncGenerationJob::class);
});

it('resubmits, with the same key, a pending generation whose submission job is gone', function () {
    $generation = Generation::factory()->create();
    $this->travel(4)->minutes();

    runCheck($generation, null);

    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generationId === $generation->id);
});

it('leaves a pending generation alone while its submission is still being retried', function () {
    $generation = Generation::factory()->create();

    runCheck($generation, null);

    Queue::assertNotPushed(SubmitGenerationJob::class);
});

it('fits Laravel Cloud managed queues: delays of 15 minutes at most, jobs well under 90 seconds', function () {
    $rungs = SyncGenerationJob::FOLLOW_UPS;
    $gaps = array_map(fn (int $i): int => $rungs[$i] - ($rungs[$i - 1] ?? 0), array_keys($rungs));

    expect(max($gaps))->toBeLessThanOrEqual(900)
        ->and(SubmitGenerationAction::MAX_QUEUE_DELAY_SECONDS)->toBe(900)
        ->and((new SubmitGenerationJob('id'))->backoff())->each->toBeLessThanOrEqual(900)
        ->and((new ProcessPixelforgeEventJob('evt'))->backoff())->each->toBeLessThanOrEqual(900)
        ->and((new SubmitGenerationJob('id'))->timeout)->toBeLessThan(90)
        ->and((new SyncGenerationJob('id'))->timeout)->toBeLessThan(90)
        ->and((new ProcessPixelforgeEventJob('evt'))->timeout)->toBeLessThan(90);
});
