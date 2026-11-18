<?php

declare(strict_types=1);

namespace App\Domain\User\Requests\Settings;

use App\Support\FormRequest;
use Laravel\Fortify\InteractsWithTwoFactorState;

/**
 * Carries Fortify's two-factor state helpers for the security page; no input.
 */
class TwoFactorAuthenticationRequest extends FormRequest
{
    use InteractsWithTwoFactorState;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }
}
