<?php

declare(strict_types=1);

namespace App\Domain\Generation\Jobs;

use App\Domain\Generation\Actions\SubmitGenerationAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue mechanics for sending a generation to Pixelforge: the policy (what to
 * retry, how long to wait) lives in SubmitGenerationAction, this job only
 * releases itself for as long as the action asks.
 */
final class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Hard ceiling; the action's policy normally finishes well before it. */
    public int $tries = 40;

    /** Unexpected crashes (not Pixelforge answers) before handing over to recovery. */
    public int $maxExceptions = 3;

    /** One submission job per generation at a time, even when recovery re-dispatches. */
    public int $uniqueFor = 3600;

    public int $timeout = 60;

    public readonly int $queuedAt;

    public function __construct(public readonly string $generationId)
    {
        $this->queuedAt = now()->getTimestamp();
    }

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /**
     * Backoff after an unexpected exception (Pixelforge answers use release() below).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $retryIn = SubmitGenerationAction::run($this->generationId, $this->attempts(), now()->getTimestamp() - $this->queuedAt);

        if (is_int($retryIn)) {
            $this->release($retryIn);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Crashed repeatedly without a Pixelforge answer. The row stays pending; the
        // history page's repair (or the reconcile sweep) resubmits it with the same key.
        Log::error('Generation submission job failed; leaving it to recovery.', [
            'generation_id' => $this->generationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
