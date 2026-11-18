<?php

declare(strict_types=1);

namespace App\Domain\User\Requests\Settings;

use App\Domain\User\Concerns\PasswordValidationRules;
use App\Support\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, array<int, Password|string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
