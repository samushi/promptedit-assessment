<?php

declare(strict_types=1);

namespace App\Domain\Billing\Requests;

use App\Support\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    /** Fixed top-up packages in USD (fake purchases, no payment). */
    public const array PACKAGES = ['5.00', '10.00', '25.00'];

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', Rule::in(self::PACKAGES)],
            // One id per click, so a double submit adds credits once.
            'reference' => ['required', 'uuid'],
        ];
    }
}
