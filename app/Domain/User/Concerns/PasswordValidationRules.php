<?php

declare(strict_types=1);

namespace App\Domain\User\Concerns;

use Illuminate\Validation\Rules\Password;

/**
 * Password rules shared by registration, password reset and the settings forms.
 */
trait PasswordValidationRules
{
    /**
     * @return array<int, Password|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * @return array<int, string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
