<?php

use App\Domain\Generation\Actions\RecoverGenerationAction;
use App\Domain\Generation\Actions\RepairStaleGenerationsAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\SyncGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;

// Opening the history repairs rows that look stuck, so a reviewer who opens the
// app long after its webhooks and follow-up checks were lost still sees the truth.

beforeEach(function () {
    Queue::fake();
    fakePixelforgePricing();
    $this->user = User::factory()->create();
});

function viewHistory(User $user): void
{
    test()->actingAs($user)->get(route('generations.index'))->assertOk();
}

it('queues a background check for a generation that looks stuck', function () {
    $generation = Generation::factory()->for($this->user)->processing('gen_A')->create();
    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();

    viewHistory($this->user);

    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->generationId === $generation->id
        && $job->followUp === null);
});

it('tries once per window per generation, whatever the result, across polls and tabs', function () {
    Generation::factory()->for($this->user)->processing('gen_A')->create();
    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();

    viewHistory($this->user);
    viewHistory($this->user);   // the next 3-second poll, or another tab
    Queue::assertPushed(SyncGenerationJob::class, 1);

    // A worker ran that check (releasing its unique lock) and nothing changed:
    // still no new attempt until the window has passed.
    Queue::pushed(SyncGenerationJob::class)->each(fn (SyncGenerationJob $job) => (new UniqueLock(app(Cache::class)))->release($job));
    viewHistory($this->user);
    Queue::assertPushed(SyncGenerationJob::class, 1);

    $this->travel(RepairStaleGenerationsAction::ATTEMPT_WINDOW_SECONDS + 1)->seconds();
    viewHistory($this->user);
    Queue::assertPushed(SyncGenerationJob::class, 2);
});

it('leaves progressing and finished generations alone', function () {
    Generation::factory()->for($this->user)->processing('gen_A')->create();
    Generation::factory()->for($this->user)->create(); // pending, its submission still active
    Generation::factory()->for($this->user)->processing('gen_B')->create([
        'status' => GenerationStatus::SUCCEEDED,
        'updated_at' => now()->subDays(3),
    ]);

    viewHistory($this->user);

    Queue::assertNotPushed(SyncGenerationJob::class);
});

it('repairs pending generations whose submission went quiet', function () {
    $generation = Generation::factory()->for($this->user)->create();
    $this->travel(RecoverGenerationAction::RESUBMIT_AFTER_SECONDS + 1)->seconds();

    viewHistory($this->user);

    Queue::assertPushed(SyncGenerationJob::class, fn (SyncGenerationJob $job): bool => $job->generationId === $generation->id);
});

it('queues at most a few checks per page view', function () {
    Generation::factory()->for($this->user)->count(5)->sequence(
        fn ($sequence) => ['provider_generation_id' => "gen_{$sequence->index}"],
    )->processing()->create();
    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();

    viewHistory($this->user);

    Queue::assertPushed(SyncGenerationJob::class, RepairStaleGenerationsAction::MAX_CHECKS_PER_REQUEST);
});

it('only repairs the viewer\'s own generations', function () {
    Generation::factory()->processing('gen_A')->create(); // someone else's
    $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();

    viewHistory($this->user);

    Queue::assertNotPushed(SyncGenerationJob::class);
});
