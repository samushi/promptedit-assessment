<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Exceptions\GenerationMismatch;
use App\Domain\Generation\Exceptions\PixelforgeException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Services\PixelforgeClient;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\Log;

/**
 * Asks Pixelforge for an accepted generation (GET /v1/generations/{id}) when
 * its webhooks seem lost. The response is authoritative and goes through the
 * same snapshot path as webhooks, so a late webhook can't bill it again.
 */
class SyncGenerationAction extends ActionFactory
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return Generation the generation afterwards (unchanged if Pixelforge couldn't be asked)
     */
    public function handle(Generation $generation): Generation
    {
        if ($generation->provider_generation_id === null || $generation->status->isTerminal()) {
            return $generation;
        }

        try {
            return ApplyProviderSnapshotAction::run($generation, $this->pixelforge->getGeneration($generation->provider_generation_id));
        } catch (PixelforgeException|GenerationMismatch $e) {
            // 429s, outages and timeouts: wait for the next check rather than spend more of the rate limit.
            Log::warning('Could not sync generation from Pixelforge.', ['generation_id' => $generation->id, 'error' => $e->getMessage()]);

            return $generation;
        }
    }
}
