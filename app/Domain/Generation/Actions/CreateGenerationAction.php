<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InsufficientCredits;
use App\Domain\Billing\Services\CreditLedger;
use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\GenerationLimitReached;
use App\Domain\Generation\Exceptions\PricingUnavailable;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\User\Models\User;
use App\Domain\User\Repository\UserRepository;
use App\Support\Actions\ActionFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a new generation and queues its submission to Pixelforge.
 *
 * The row (with its immutable request body and Idempotency-Key) is saved
 * before anything is sent, so every retry, early webhook and reconciliation
 * has something durable to refer to.
 */
class CreateGenerationAction extends ActionFactory
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly UserRepository $users,
        private readonly CreditLedger $credits,
    ) {}

    /**
     * @throws PricingUnavailable
     * @throws GenerationLimitReached when a daily cap is reached
     * @throws InsufficientCredits when the balance can't cover the most this could cost
     */
    public function handle(User $user, CreateGenerationDto $request): Generation
    {
        // The server's own quote: the browser's numbers are never trusted.
        $estimate = EstimateGenerationCostAction::run($request);
        $clientRequestId = strtolower((string) $request->clientRequestId);

        return DB::transaction(function () use ($user, $request, $clientRequestId, $estimate): Generation {
            // Serialise this user's submissions and spending: the duplicate check
            // and the balance check below can't race with another request.
            $this->users->lockForUpdate($user->id);

            // The same form submitted twice (double click, resent request) is one generation.
            $existing = $this->generations->findByClientRequestId($user, $clientRequestId);

            if ($existing !== null) {
                return $existing;
            }

            $this->ensureWithinDailyLimits($user);

            $available = $this->credits->balance($user->id);

            if ($available->isLessThan($estimate->customerMax)) {
                throw new InsufficientCredits($estimate->customerMax, $available);
            }

            $id = (string) Str::uuid7();

            $generation = $this->generations->createWithId([
                'id' => $id,
                'user_id' => $user->id,
                'client_request_id' => $clientRequestId,
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::PENDING,
                'prompt' => $request->prompt,
                'size' => $request->size(),
                'quality' => $request->quality(),
                'reference_images' => $request->referenceImages(),
                'provider_request' => $request->toProviderPayload($id),
                'estimated_provider_cost_usd' => $estimate->providerExpected,
                'estimated_customer_cost_usd' => $estimate->customerExpected,
                'max_customer_cost_usd' => $estimate->customerMax,
            ]);

            // Reserve the maximum possible price until the real one is known.
            $this->credits->hold($generation, $estimate->customerMax);

            // Queued once the transaction commits, so the worker always finds the row.
            // If the process died in between, the history page's repair (or the
            // pixelforge:reconcile sweep) resubmits the pending row with the same key.
            SubmitGenerationJob::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }

    /**
     * Caps on how many generations can be created per UTC day, per user and in
     * total, so a public demo can't be run up by scripts. The per-user count
     * runs under the user's row lock; the total is a soft cap (concurrent users
     * may overshoot it by a few).
     *
     * @throws GenerationLimitReached
     */
    private function ensureWithinDailyLimits(User $user): void
    {
        $today = now()->utc()->startOfDay();
        $perUser = Config::integer('services.pixelforge.limits.per_user_per_day');
        $total = Config::integer('services.pixelforge.limits.per_day');

        if ($perUser > 0 && $this->generations->countCreatedSince($today, $user) >= $perUser) {
            throw GenerationLimitReached::forUser($perUser);
        }

        if ($total > 0 && $this->generations->countCreatedSince($today) >= $total) {
            throw GenerationLimitReached::forEveryone();
        }
    }
}
