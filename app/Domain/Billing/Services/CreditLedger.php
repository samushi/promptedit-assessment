<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\CreditTransactionType;
use App\Domain\Billing\Repository\CreditTransactionRepository;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Brick\Math\BigDecimal;

/**
 * Credits are US dollars, kept in an append-only ledger.
 *
 * Reservation policy: the true price is only known when a generation finishes,
 * so submitting one holds the most it can possibly cost (prompt text +15%,
 * maximum reasoning). When it finishes the hold is released and, if it
 * succeeded, the exact price is charged. Failed or rejected generations
 * release their hold and cost nothing.
 *
 * Every write is an INSERT ... ON CONFLICT DO NOTHING against a unique key, so
 * a duplicate webhook, a retry or a racing poll can never move money twice.
 */
class CreditLedger
{
    public function __construct(private readonly CreditTransactionRepository $transactions) {}

    /** What the user can spend now (in-flight reservations already deducted). */
    public function balance(int $userId): BigDecimal
    {
        return $this->transactions->sumForUser($userId, CreditTransactionType::cases());
    }

    /** Held for generations that haven't finished yet (a positive amount). */
    public function reserved(int $userId): BigDecimal
    {
        return $this->transactions->sumForUser($userId, [CreditTransactionType::HOLD, CreditTransactionType::RELEASE])->negated();
    }

    /**
     * A fake top-up. The reference makes a repeated click count once.
     *
     * @return bool false if this purchase was already recorded
     */
    public function purchase(int $userId, BigDecimal $amount, string $reference): bool
    {
        return $this->transactions->record($userId, null, CreditTransactionType::PURCHASE, $amount, $reference);
    }

    /**
     * Reserve the most this generation can cost. Call it in the same transaction
     * that created the generation, with the user's row locked.
     */
    public function hold(Generation $generation, BigDecimal $maximum): void
    {
        $this->transactions->record($generation->user_id, $generation->id, CreditTransactionType::HOLD, $maximum->negated());
    }

    /**
     * Settle a generation that reached a final status: give back its hold and,
     * if it succeeded, charge its exact price. Safe to call more than once.
     */
    public function settle(Generation $generation): void
    {
        $hold = $this->transactions->findForGeneration($generation->id, CreditTransactionType::HOLD);

        if ($hold !== null) {
            $this->transactions->record($generation->user_id, $generation->id, CreditTransactionType::RELEASE, $hold->amount_usd->negated());
        }

        $price = $generation->actual_customer_cost_usd;

        if ($generation->status === GenerationStatus::SUCCEEDED && $price !== null && $price->isPositive()) {
            $this->transactions->record($generation->user_id, $generation->id, CreditTransactionType::CHARGE, $price->negated());
        }
    }
}
