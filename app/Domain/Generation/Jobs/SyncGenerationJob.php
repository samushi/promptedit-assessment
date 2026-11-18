<?php

declare(strict_types=1);

namespace App\Domain\Generation\Jobs;

use App\Domain\Generation\Actions\RecoverGenerationAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Checks one generation (RecoverGenerationAction).
 *
 * In production there is no recurring scheduler, so these checks are what
 * recovers a generation whose webhooks were lost:
 *  - follow-ups: when Pixelforge accepts a generation, a short, fixed ladder of
 *    checks runs 2, 5 and 10 minutes after acceptance, and stops as soon as the
 *    generation is final (usually at the first rung, if webhooks haven't
 *    already settled it);
 *  - one-off checks: queued by the history page for rows that look stuck
 *    (RepairStaleGenerationsAction) and by the pixelforge:reconcile sweep.
 *
 * Every delay stays within the managed queue's 15-minute limit, and each run is
 * one short HTTP request, far inside the 90 seconds a Flex worker allows.
 */
final class SyncGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Seconds after acceptance at which the follow-up checks run. */
    public const array FOLLOW_UPS = [120, 300, 600];

    /** A failed check isn't retried: the next follow-up (or a page view) tries again. */
    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * @param  int|null  $followUp  index into FOLLOW_UPS, or null for a one-off check
     */
    public function __construct(
        public readonly string $generationId,
        public readonly ?int $followUp = null,
    ) {}

    /**
     * Queues the first follow-up for a generation Pixelforge has just accepted.
     */
    public static function startFollowUps(string $generationId): void
    {
        // The sync driver ignores delays: it would run every check straight away.
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }

        self::dispatch($generationId, 0)->delay(self::FOLLOW_UPS[0])->afterCommit();
    }

    public function uniqueId(): string
    {
        // Each rung is its own job: the next one is queued while this one still holds its lock.
        return $this->generationId.':'.($this->followUp ?? 'check');
    }

    public function handle(): void
    {
        $final = false;

        try {
            $final = RecoverGenerationAction::run($this->generationId)->isFinal();
        } finally {
            // Also after an unexpected error, so one bad run doesn't end the ladder.
            if (! $final) {
                $this->queueNextFollowUp();
            }
        }
    }

    private function queueNextFollowUp(): void
    {
        if ($this->followUp === null || ! isset(self::FOLLOW_UPS[$this->followUp + 1])) {
            return;
        }

        $next = $this->followUp + 1;

        self::dispatch($this->generationId, $next)->delay(self::FOLLOW_UPS[$next] - self::FOLLOW_UPS[$this->followUp]);
    }
}
