<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\RecoveryOutcome;
use App\Domain\Generation\Jobs\ProcessPixelforgeEventJob;
use App\Domain\Generation\Jobs\SyncGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\Generation\Repository\PixelforgeWebhookEventRepository;
use App\Support\Actions\ActionFactory;

/**
 * The sweep (pixelforge:reconcile): checks every generation and webhook event
 * that may have been left behind. Locally the scheduler runs it every minute.
 * In production it is off by default (see GenerationServiceProvider), because
 * the per-generation follow-ups and the history page's repair cover lost
 * webhooks without waking the app every minute; run it by hand when needed.
 *
 * Batches are small because every Pixelforge call shares the account's
 * 60 requests/minute with live submissions.
 */
class ReconcileGenerationsAction extends ActionFactory
{
    public const int BATCH_SIZE = 10;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgeWebhookEventRepository $events,
    ) {}

    /**
     * @return array{synced: int, resubmitted: int, abandoned: int, events: int}
     */
    public function handle(): array
    {
        $giveUpBefore = now()->subHours(RecoverGenerationAction::GIVE_UP_AFTER_HOURS);

        // 1. Accepted but unfinished and quiet: webhooks may have been missed.
        $quiet = $this->generations->quietInFlight(
            now()->subSeconds(RecoverGenerationAction::SYNC_AFTER_SECONDS),
            $giveUpBefore,
            self::BATCH_SIZE,
        );

        foreach ($quiet as $generation) {
            SyncGenerationJob::dispatch($generation->id);
        }

        // 2. Never confirmed by Pixelforge and no submission activity: resubmit, or give up after 24 hours.
        $outcomes = $this->generations
            ->stalePending(now()->subSeconds(RecoverGenerationAction::RESUBMIT_AFTER_SECONDS), self::BATCH_SIZE)
            ->map(fn (Generation $generation): RecoveryOutcome => RecoverGenerationAction::run($generation->id));

        // 3. Stored webhook events whose processing job was lost or kept failing.
        $events = $this->events->unprocessed(now()->subMinutes(2), maxAttempts: 10, limit: 50);

        foreach ($events as $event) {
            ProcessPixelforgeEventJob::dispatch($event->event_id);
        }

        return [
            'synced' => $quiet->count(),
            'resubmitted' => $outcomes->filter(fn (RecoveryOutcome $outcome): bool => $outcome === RecoveryOutcome::RESUBMITTED)->count(),
            'abandoned' => $outcomes->filter(fn (RecoveryOutcome $outcome): bool => $outcome === RecoveryOutcome::ABANDONED)->count(),
            'events' => $events->count(),
        ];
    }
}
