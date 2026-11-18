<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Jobs\SyncGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Read repair for the history page: rows that look stuck get a background
 * check (SyncGenerationJob), so a reviewer opening the app days later never
 * finds a generation frozen because its webhooks and follow-ups were lost.
 *
 * The page polls every few seconds while something is in flight, and every
 * check may cost a request of Pixelforge's 60/minute, so checks are limited
 * per generation: one attempt per window, whatever its result (no change, 429,
 * timeout), claimed atomically so several tabs can't both queue one.
 */
class RepairStaleGenerationsAction extends ActionFactory
{
    public const int ATTEMPT_WINDOW_SECONDS = 120;

    public const int MAX_CHECKS_PER_REQUEST = 3;

    /**
     * @param  iterable<Generation>  $generations
     * @return int checks queued
     */
    public function handle(iterable $generations): int
    {
        $queued = 0;

        foreach ($generations as $generation) {
            if ($queued === self::MAX_CHECKS_PER_REQUEST) {
                break;
            }

            if (! RecoverGenerationAction::looksStuck($generation)) {
                continue;
            }

            if (! Cache::add("generations:{$generation->id}:repair", true, self::ATTEMPT_WINDOW_SECONDS)) {
                continue;
            }

            SyncGenerationJob::dispatch($generation->id);
            $queued++;
        }

        return $queued;
    }
}
