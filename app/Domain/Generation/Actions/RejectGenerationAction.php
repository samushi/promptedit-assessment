<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Services\CreditLedger;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Support\Actions\ActionFactory;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Marks a submission that Pixelforge never accepted: nothing was created, so
 * nothing is billed and the credit hold is released. Only a still-pending
 * generation can be rejected.
 */
class RejectGenerationAction extends ActionFactory
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly CreditLedger $credits,
    ) {}

    public function handle(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message): Generation {
            $locked = $this->generations->lockForUpdate($generation->id);

            // Pixelforge evidence (e.g. an early webhook) already moved it on: keep that.
            if ($locked->status !== GenerationStatus::PENDING) {
                return $locked;
            }

            $locked->status = GenerationStatus::REJECTED;
            $locked->error_code = $errorCode;
            $locked->error_message = $message;
            $locked->actual_provider_cost_usd = BigDecimal::zero();
            $locked->actual_customer_cost_usd = BigDecimal::zero();
            $locked->save();

            $this->credits->settle($locked);

            return $locked;
        });
    }
}
