<?php

declare(strict_types=1);

namespace App\Domain\User\Requests\Settings;

use App\Domain\User\Concerns\ProfileValidationRules;
use App\Support\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        if (config('fortify.lowercase_usernames') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
