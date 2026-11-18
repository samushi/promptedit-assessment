<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\CreditTransactionType;
use App\Support\Casts\MoneyCast;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only entry in a user's credit ledger.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $generation_id
 * @property CreditTransactionType $type
 * @property BigDecimal $amount_usd
 * @property string|null $reference
 * @property CarbonImmutable $created_at
 */
#[Unguarded]
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_usd' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
