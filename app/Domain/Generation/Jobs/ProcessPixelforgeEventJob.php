<?php

declare(strict_types=1);

namespace App\Domain\Generation\Jobs;

use App\Domain\Generation\Actions\ProcessWebhookEventAction;
use App\Domain\Generation\Repository\PixelforgeWebhookEventRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes a stored webhook event. Failures are retried here (every delay is
 * within a managed queue's 15-minute limit). If it still fails, the event stays
 * in the inbox unprocessed: the generation's follow-up checks ask Pixelforge for
 * its status (authoritative) anyway, and the pixelforge:reconcile sweep re-runs
 * such events.
 */
final class ProcessPixelforgeEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly string $eventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(): void
    {
        ProcessWebhookEventAction::run($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(PixelforgeWebhookEventRepository::class)->recordError($this->eventId, (string) $exception?->getMessage());

        Log::error('Processing a Pixelforge webhook event failed.', ['event_id' => $this->eventId, 'error' => $exception?->getMessage()]);
    }
}
