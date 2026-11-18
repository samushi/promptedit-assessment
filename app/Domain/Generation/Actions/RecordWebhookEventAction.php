<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\WebhookEventType;
use App\Domain\Generation\Jobs\ProcessPixelforgeEventJob;
use App\Domain\Generation\Repository\PixelforgeWebhookEventRepository;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\DB;

/**
 * Durably stores a verified webhook (deduplicated by event id) and queues its
 * processing. Only after this returns do we answer Pixelforge with a 2xx.
 */
class RecordWebhookEventAction extends ActionFactory
{
    public function __construct(private readonly PixelforgeWebhookEventRepository $events) {}

    /**
     * @return bool false when this event id was already received (a redelivery)
     */
    public function handle(string $eventId, string $type, ?string $providerGenerationId, string $rawBody, ?int $deliveryAttempt): bool
    {
        $needsProcessing = WebhookEventType::tryFrom($type)?->handler() !== null;

        return DB::transaction(function () use ($eventId, $type, $providerGenerationId, $rawBody, $deliveryAttempt, $needsProcessing): bool {
            // The unique index on event_id turns a redelivery into a no-op (ON CONFLICT DO NOTHING).
            $inserted = $this->events->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $type,
                'provider_generation_id' => $providerGenerationId,
                'payload' => $rawBody,
                'delivery_attempt' => $deliveryAttempt,
                'received_at' => now(),
                // Pings and unknown event types need no work: accept and close them.
                'processed_at' => $needsProcessing ? null : now(),
            ]);

            if ($inserted === 1 && $needsProcessing) {
                // Queued once the event row is committed. If that dispatch were ever
                // lost, the follow-up checks still settle the generation from a GET,
                // and the reconcile sweep re-runs events left unprocessed.
                ProcessPixelforgeEventJob::dispatch($eventId)->afterCommit();
            }

            return $inserted === 1;
        });
    }
}
