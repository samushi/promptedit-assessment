<?php

declare(strict_types=1);

namespace App\Domain\User\Actions\Fortify;

use App\Domain\User\Concerns\PasswordValidationRules;
use App\Domain\User\Concerns\ProfileValidationRules;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Registration. Fortify owns the auth routes and calls this through its
 * contract (create()), registered in FortifyServiceProvider, so it isn't run
 * through ActionFactory.
 */
class CreateNewUserAction implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
