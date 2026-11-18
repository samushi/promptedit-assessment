<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Dto\PurchaseCreditsDto;
use App\Domain\Billing\Services\CreditLedger;
use App\Domain\User\Models\User;
use App\Support\Actions\ActionFactory;
use App\Support\Money;

/**
 * Fake purchase: adds the package's dollars to the balance. No payment is taken.
 */
class PurchaseCreditsAction extends ActionFactory
{
    public function __construct(private readonly CreditLedger $credits) {}

    /**
     * @return bool false when this click was already recorded
     */
    public function handle(User $user, PurchaseCreditsDto $purchase): bool
    {
        return $this->credits->purchase($user->id, Money::of($purchase->amount), strtolower($purchase->reference));
    }
}
