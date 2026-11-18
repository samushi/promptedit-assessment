<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;

/**
 * Base class for a domain's form requests.
 *
 * Unlike an API, Inertia keeps Laravel's validation responses: failed page
 * submissions redirect back with errors that the form displays, and JSON
 * requests get the usual 422.
 */
abstract class FormRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|Closure|string|object>|string>
     */
    abstract public function rules(): array;

    /**
     * Routes are protected by middleware; record-level access lives in the actions.
     */
    public function authorize(): bool
    {
        return true;
    }
}
