<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Services\CreditLedger;
use App\Domain\Generation\Dto\ProviderGeneration;
use App\Domain\Generation\Dto\TokenRates;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\GenerationMismatch;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Jobs\SyncGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\Generation\Services\CostCalculator;
use App\Support\Actions\ActionFactory;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies what Pixelforge says about a generation (POST/GET response or webhook).
 *
 * This is the only place a generation's status advances and the only place it
 * is billed. It runs in a transaction under a row lock, so a webhook, a
 * duplicate webhook and a reconciliation poll racing on the same generation are
 * serialised: whoever comes second finds a final status and changes nothing.
 */
class ApplyProviderSnapshotAction extends ActionFactory
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly CostCalculator $costs,
        private readonly CreditLedger $credits,
    ) {}

    /**
     * @throws GenerationMismatch when the snapshot belongs to a different Pixelforge generation
     */
    public function handle(Generation $generation, ProviderGeneration $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $locked = $this->generations->lockForUpdate($generation->id);

            if ($locked->provider_generation_id !== null && $locked->provider_generation_id !== $snapshot->id) {
                throw new GenerationMismatch("Generation {$locked->id} is {$locked->provider_generation_id}, not {$snapshot->id}.");
            }

            // The first time we hear Pixelforge's id for it, whichever way that news
            // arrives (POST response, early webhook, GET): the row lock makes it once.
            $firstAcceptance = $locked->provider_generation_id === null;

            $this->backfill($locked, $snapshot);
            $finishesNow = false;

            if ($locked->status->canAdvanceTo($snapshot->status)) {
                $this->advance($locked, $snapshot);
                $finishesNow = $snapshot->status->isTerminal();
            } elseif ($locked->status->isTerminal() && $snapshot->status->isTerminal() && $locked->status !== $snapshot->status) {
                Log::error('Pixelforge reported a different final status for a finished generation; keeping ours.', [
                    'generation_id' => $locked->id,
                    'ours' => $locked->status->value,
                    'reported' => $snapshot->status->value,
                ]);
            }

            $locked->save();

            if ($finishesNow) {
                // Same transaction: the user's balance moves exactly when the status does.
                $this->credits->settle($locked);
            }

            if ($firstAcceptance && ! $locked->status->isTerminal()) {
                // Recovers the generation if its webhooks get lost (queued after commit).
                SyncGenerationJob::startFollowUps($locked->id);
            }

            return $locked;
        });
    }

    /**
     * Identity fields are filled in whenever they are still missing, whatever
     * order snapshots arrive in. They never change once set: in particular the
     * rate snapshot that the generation is billed at.
     */
    private function backfill(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->provider_generation_id ??= $snapshot->id;
        $generation->rates ??= $snapshot->rates?->toArray();
        $generation->accepted_at ??= $snapshot->createdAt;
        $generation->started_at ??= $snapshot->startedAt;
    }

    private function advance(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->status = $snapshot->status;
        // Clears "retrying…" notes left by submission attempts.
        $generation->error_code = null;
        $generation->error_message = null;

        if ($snapshot->status === GenerationStatus::SUCCEEDED) {
            $this->settleSuccess($generation, $snapshot);
        } elseif ($snapshot->status === GenerationStatus::FAILED) {
            $this->settleFailure($generation, $snapshot);
        } else {
            // Not billed yet (also undoes the $0 of a rejection that Pixelforge proved wrong).
            $generation->actual_provider_cost_usd = null;
            $generation->actual_customer_cost_usd = null;
        }
    }

    /**
     * Exact cost from actual usage at the generation's own rate snapshot.
     * Reached at most once per generation: afterwards the status is final.
     */
    private function settleSuccess(Generation $generation, ProviderGeneration $snapshot): void
    {
        if ($snapshot->usage === null || $generation->rates === null) {
            throw new InvalidPixelforgePayload("Cannot bill generation {$generation->id} without usage and rates.");
        }

        if (! $snapshot->usage->isConsistent()) {
            Log::warning('Pixelforge usage total_tokens does not match the sum of its parts.', ['generation_id' => $generation->id]);
        }

        $providerCost = $this->costs->providerCost($snapshot->usage, TokenRates::fromArray($generation->rates));

        $generation->usage = $snapshot->usage->toArray();
        $generation->actual_provider_cost_usd = $providerCost;
        $generation->actual_customer_cost_usd = $this->costs->customerPrice($providerCost);
        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        // Pixelforge's completion time, never ours: it decides the billing day.
        $generation->completed_at = $snapshot->completedAt;
    }

    /** Failed generations are never billed. */
    private function settleFailure(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->actual_provider_cost_usd = BigDecimal::zero();
        $generation->actual_customer_cost_usd = BigDecimal::zero();
        $generation->error_code = $snapshot->errorCode ?? 'generation_failed';
        $generation->error_message = $snapshot->errorMessage;
        $generation->completed_at = $snapshot->completedAt;
    }
}
