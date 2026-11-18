<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\WebhookEventType;
use App\Domain\Generation\Exceptions\GenerationMismatch;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Exceptions\UnknownGeneration;
use App\Domain\Generation\Repository\PixelforgeWebhookEventRepository;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\Log;

/**
 * Runs a stored webhook event through the handler for its type (WebhookEvents/).
 *
 * Problems that a retry can't fix (an unreadable event, a generation we don't
 * know, a mismatched id) close the event with a note; the generation's
 * follow-up checks ask Pixelforge for it instead. Anything else throws, so the queue retries it.
 */
class ProcessWebhookEventAction extends ActionFactory
{
    public function __construct(private readonly PixelforgeWebhookEventRepository $events) {}

    public function handle(string $eventId): void
    {
        $event = $this->events->findByEventId($eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $this->events->countProcessingAttempt($event);
        $handler = WebhookEventType::tryFrom($event->type)?->handler();

        if ($handler === null) {
            $this->events->markProcessed($event, 'No handler for this event type.');

            return;
        }

        try {
            app($handler)->handle($event);
        } catch (InvalidPixelforgePayload|UnknownGeneration|GenerationMismatch $e) {
            Log::warning('Pixelforge webhook event could not be applied.', ['event_id' => $event->event_id, 'error' => $e->getMessage()]);
            $this->events->markProcessed($event, $e->getMessage());

            return;
        }

        $this->events->markProcessed($event);
    }
}
