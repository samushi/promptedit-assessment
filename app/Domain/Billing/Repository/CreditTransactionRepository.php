<?php

declare(strict_types=1);

namespace App\Domain\Billing\Repository;

use App\Domain\Billing\Enums\CreditTransactionType;
use App\Domain\Billing\Models\CreditTransaction;
use App\Support\Money;
use App\Support\Repositories;
use Brick\Math\BigDecimal;

/**
 * @extends Repositories<CreditTransaction>
 */
class CreditTransactionRepository extends Repositories
{
    /**
     * Exact sum of a user's entries of the given types (Postgres sums NUMERIC
     * exactly; the result is read back as a decimal string).
     *
     * @param  list<CreditTransactionType>  $types
     */
    public function sumForUser(int $userId, array $types): BigDecimal
    {
        $sum = $this->query()
            ->where('user_id', $userId)
            ->whereIn('type', $types)
            ->toBase()
            ->selectRaw('coalesce(sum(amount_usd), 0)::text as total')
            ->value('total');

        return Money::of(is_string($sum) ? $sum : '0');
    }

    public function findForGeneration(string $generationId, CreditTransactionType $type): ?CreditTransaction
    {
        return $this->query()
            ->where('generation_id', $generationId)
            ->where('type', $type)
            ->first();
    }

    /**
     * Appends an entry unless its unique key (generation + type, or a purchase
     * reference) already exists.
     *
     * @return bool false when it was already recorded
     */
    public function record(?int $userId, ?string $generationId, CreditTransactionType $type, BigDecimal $amount, ?string $reference = null): bool
    {
        return $this->insertOrIgnore([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type->value,
            'amount_usd' => Money::toStorage($amount),
            'reference' => $reference,
            'created_at' => now(),
        ]) === 1;
    }

    protected function getModel(): CreditTransaction
    {
        return new CreditTransaction;
    }
}
