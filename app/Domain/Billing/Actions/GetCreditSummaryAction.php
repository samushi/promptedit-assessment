<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Requests\PurchaseCreditsRequest;
use App\Domain\Billing\Services\CreditLedger;
use App\Domain\User\Models\User;
use App\Support\Actions\ActionFactory;
use App\Support\Money;

/**
 * The balance bar on the generate page. Amounts are exact decimal strings.
 */
class GetCreditSummaryAction extends ActionFactory
{
    public function __construct(private readonly CreditLedger $credits) {}

    /**
     * @return array{balance: string, reserved: string, packages: list<string>}
     */
    public function handle(User $user): array
    {
        return [
            'balance' => Money::toWire($this->credits->balance($user->id)),
            'reserved' => Money::toWire($this->credits->reserved($user->id)),
            'packages' => PurchaseCreditsRequest::PACKAGES,
        ];
    }
}
