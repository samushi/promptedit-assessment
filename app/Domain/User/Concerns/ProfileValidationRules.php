<?php

declare(strict_types=1);

namespace App\Domain\User\Concerns;

use App\Domain\User\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Name and email rules shared by registration and the profile settings form.
 */
trait ProfileValidationRules
{
    /**
     * @return array<string, array<int, Unique|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, Unique|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
