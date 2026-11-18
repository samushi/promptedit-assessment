<?php

declare(strict_types=1);

namespace App\Domain\User\Requests\Settings;

use App\Domain\User\Concerns\PasswordValidationRules;
use App\Support\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
