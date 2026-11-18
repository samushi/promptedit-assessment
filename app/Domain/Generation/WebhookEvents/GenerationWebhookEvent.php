<?php

declare(strict_types=1);

namespace App\Domain\Generation\WebhookEvents;

use App\Domain\Generation\Actions\ApplyProviderSnapshotAction;
use App\Domain\Generation\Dto\ProviderGeneration;
use App\Domain\Generation\Exceptions\GenerationMismatch;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Exceptions\UnknownGeneration;
use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Domain\Generation\Repository\GenerationRepository;

/**
 * generation.processing / succeeded / failed: the event carries the whole
 * generation object, which is applied like any other snapshot.
 *
 * Arrival order doesn't matter: snapshots are applied by status rank, so a late
 * "processing" never undoes "succeeded", and billing happens only on the first
 * transition to a final status.
 */
class GenerationWebhookEvent
{
    public function __construct(private readonly GenerationRepository $generations) {}

    /**
     * @throws InvalidPixelforgePayload when the event's generation object is unreadable
     * @throws UnknownGeneration when no local generation matches
     * @throws GenerationMismatch when it matches a row linked to another Pixelforge generation
     */
    public function handle(PixelforgeWebhookEvent $event): void
    {
        $snapshot = ProviderGeneration::fromArray($event->generationObject() ?? []);
        $generation = $this->generations->findForSnapshot($snapshot);

        if ($generation === null) {
            throw new UnknownGeneration("No generation matches {$snapshot->id}.");
        }

        ApplyProviderSnapshotAction::run($generation, $snapshot);
    }
}
