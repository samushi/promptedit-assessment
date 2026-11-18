<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Actions\PurchaseCreditsAction;
use App\Domain\Billing\Dto\PurchaseCreditsDto;
use App\Domain\Billing\Requests\PurchaseCreditsRequest;
use App\Domain\User\Models\User;
use App\Support\InertiaControllers;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

class CreditsController extends InertiaControllers
{
    /** "Buy credits": a fake purchase that simply adds dollars to the balance. */
    public function store(PurchaseCreditsRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $purchase = PurchaseCreditsDto::fromRequest($request);

        if (PurchaseCreditsAction::run($user, $purchase) === true) {
            $this->toast("Added \${$purchase->amount} in credits. This is a test purchase: no payment was taken.");
        }

        return back();
    }
}
