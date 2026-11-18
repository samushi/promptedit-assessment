<?php

declare(strict_types=1);

namespace App\Domain\User\Actions\Fortify;

use App\Domain\User\Concerns\PasswordValidationRules;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * "Forgot password" reset. Called by Fortify through its contract (reset()).
 */
class ResetUserPasswordAction implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
