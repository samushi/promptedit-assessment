<?php

declare(strict_types=1);

namespace App\Domain\Generation\Repository;

use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Support\Repositories;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Repositories<PixelforgeWebhookEvent>
 */
class PixelforgeWebhookEventRepository extends Repositories
{
    public function findByEventId(string $eventId): ?PixelforgeWebhookEvent
    {
        return $this->query()->where('event_id', $eventId)->first();
    }

    public function countProcessingAttempt(PixelforgeWebhookEvent $event): void
    {
        $event->increment('processing_attempts');
    }

    public function markProcessed(PixelforgeWebhookEvent $event, ?string $note = null): void
    {
        $event->forceFill(['processed_at' => now(), 'last_error' => $note])->save();
    }

    public function recordError(string $eventId, string $error): void
    {
        $this->query()->where('event_id', $eventId)->update(['last_error' => mb_substr($error, 0, 2000)]);
    }

    /**
     * Stored events whose processing job was lost or kept failing.
     *
     * @return Collection<int, PixelforgeWebhookEvent>
     */
    public function unprocessed(CarbonImmutable $receivedBefore, int $maxAttempts, int $limit): Collection
    {
        return $this->query()
            ->whereNull('processed_at')
            ->where('received_at', '<', $receivedBefore)
            ->where('processing_attempts', '<', $maxAttempts)
            ->orderBy('received_at')
            ->limit($limit)
            ->get(['id', 'event_id']);
    }

    protected function getModel(): PixelforgeWebhookEvent
    {
        return new PixelforgeWebhookEvent;
    }
}
