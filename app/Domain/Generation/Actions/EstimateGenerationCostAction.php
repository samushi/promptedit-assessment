<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Dto\CostEstimate;
use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Exceptions\PricingUnavailable;
use App\Domain\Generation\Services\CostEstimator;
use App\Domain\Generation\Services\PixelforgePricing;
use App\Support\Actions\ActionFactory;

/**
 * The server's own price quote for a request. The browser only displays it,
 * and submitting re-quotes independently.
 */
class EstimateGenerationCostAction extends ActionFactory
{
    public function __construct(
        private readonly PixelforgePricing $pricing,
        private readonly CostEstimator $estimator,
    ) {}

    /**
     * @throws PricingUnavailable
     */
    public function handle(CreateGenerationDto $request): CostEstimate
    {
        return $this->estimator->estimate($this->pricing->current(), $request);
    }
}
