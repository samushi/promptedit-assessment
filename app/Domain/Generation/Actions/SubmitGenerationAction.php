<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\PixelforgeError;
use App\Domain\Generation\Exceptions\PixelforgeException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\Generation\Services\PixelforgeClient;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\Log;

/**
 * One attempt at sending a pending generation to Pixelforge (POST /v1/generations).
 *
 * Every attempt sends the stored request body with the generation's fixed
 * Idempotency-Key, so a retry after a timeout returns the generation that may
 * already exist instead of creating (and billing) a second one.
 *
 * Retry policy, by Pixelforge's error code:
 *  - validation, content policy, auth, key reuse, daily quota: definitive, reject now;
 *  - concurrency limit: wait for a free slot (honouring Retry-After) for up to 10 minutes;
 *  - rate limit, overload: up to 6 failures per generation, honouring Retry-After
 *    with exponential backoff (waiting for a slot doesn't use up this budget);
 *  - unknown outcome (timeout, bare 5xx): same, but never reject afterwards, since
 *    Pixelforge may have created it. Recovery resends it later with the same key
 *    (see RecoverGenerationAction), and a webhook may confirm it at any time.
 */
class SubmitGenerationAction extends ActionFactory
{
    /** Failures (rate limits, overloads, unknown outcomes) before we stop retrying. */
    public const int MAX_FAILURES = 6;

    /** How long we keep waiting for one of Pixelforge's 3 concurrent slots. */
    public const int CONCURRENCY_WAIT_SECONDS = 600;

    public const int MAX_BACKOFF_SECONDS = 60;

    /**
     * The longest a job can be delayed on a managed (SQS-backed) queue such as
     * Laravel Cloud's: 15 minutes. A longer Retry-After simply gets another 429
     * after that and waits again.
     */
    public const int MAX_QUEUE_DELAY_SECONDS = 900;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgeClient $pixelforge,
    ) {}

    /**
     * @param  int  $attempt  the queue job's attempt number
     * @param  int  $secondsWaiting  how long this submission job has been trying
     * @return int|null seconds to wait before the next attempt, or null when done
     */
    public function handle(string $generationId, int $attempt, int $secondsWaiting): ?int
    {
        $generation = $this->generations->find($generationId);

        // Already accepted (perhaps through an early webhook) or finished.
        if ($generation === null || $generation->status !== GenerationStatus::PENDING) {
            return null;
        }

        $this->generations->countSubmissionAttempt($generation);

        try {
            $snapshot = $this->pixelforge->createGeneration($generation->provider_request, $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            return $this->handleFailure($generation, $e, $attempt, $secondsWaiting);
        }

        // A replayed key returns the generation as it is now, possibly already final,
        // so the response goes through the same path as webhooks.
        ApplyProviderSnapshotAction::run($generation, $snapshot);

        return null;
    }

    private function handleFailure(Generation $generation, PixelforgeException $e, int $attempt, int $secondsWaiting): ?int
    {
        $ambiguous = $generation->submission_ambiguous || $e->isAmbiguous();
        $failures = $this->recordFailure($generation, $e, $ambiguous);

        if ($e->isDefinitive()) {
            if ($e->error?->isOurFault()) {
                Log::critical('Pixelforge rejected a submission because of our configuration or code.', [
                    'generation_id' => $generation->id,
                    'error' => $e->errorCode(),
                    'message' => $e->getMessage(),
                ]);
            }

            RejectGenerationAction::run($generation, $e->errorCode(), $e->userMessage());

            return null;
        }

        if ($this->shouldRetry($e, $failures, $secondsWaiting)) {
            return $this->retryDelay($e, $failures, $attempt);
        }

        if ($ambiguous) {
            Log::warning('Pixelforge never confirmed a submission; leaving it pending for recovery.', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Every answer said "nothing was created" (429/503), so it's safe to give up.
        RejectGenerationAction::run($generation, $e->errorCode(), $e->userMessage());

        return null;
    }

    /**
     * Notes the latest problem on the row (shown as "retrying…" in the UI). The
     * failure count lives on the row because queue job state doesn't survive a
     * release().
     *
     * @return int failures counting towards the retry budget, including this one
     */
    private function recordFailure(Generation $generation, PixelforgeException $e, bool $ambiguous): int
    {
        $waitingForSlot = $e->error === PixelforgeError::CONCURRENCY_LIMIT_EXCEEDED;
        $failures = $generation->submission_failures + ($waitingForSlot ? 0 : 1);

        $this->generations->updateIfPending($generation, [
            'error_code' => $e->errorCode(),
            'error_message' => $e->getMessage(),
            'submission_ambiguous' => $ambiguous,
            'submission_failures' => $failures,
        ]);

        return $failures;
    }

    private function shouldRetry(PixelforgeException $e, int $failures, int $secondsWaiting): bool
    {
        if ($e->error === PixelforgeError::CONCURRENCY_LIMIT_EXCEEDED) {
            return $secondsWaiting < self::CONCURRENCY_WAIT_SECONDS;
        }

        return $failures < self::MAX_FAILURES;
    }

    /**
     * Exponential backoff (2, 4, 8, ... seconds, capped at a minute), but never
     * sooner than Pixelforge's Retry-After (up to the queue's 15-minute maximum).
     * Every retry costs one request of the account-wide 60/minute budget.
     */
    private function retryDelay(PixelforgeException $e, int $failures, int $attempt): int
    {
        $step = $e->error === PixelforgeError::CONCURRENCY_LIMIT_EXCEEDED ? $attempt : $failures;
        $backoff = min(self::MAX_BACKOFF_SECONDS, 2 ** min($step, 6));

        return min(self::MAX_QUEUE_DELAY_SECONDS, max($e->retryAfter ?? 0, $backoff));
    }
}
