<?php

declare(strict_types=1);

namespace App\Domain\Generation\Requests;

use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Support\FormRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Mirrors Pixelforge's own rules so invalid requests never leave the app.
 * Note: the global TrimStrings middleware trims the prompt before it is counted.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /** Anything else is rejected, not silently ignored (as Pixelforge does). */
    private const array FIELDS = ['client_request_id', 'prompt', 'size', 'quality', 'reference_images'];

    public function rules(): array
    {
        return [
            // One id per form submission, so a double submit creates one generation.
            'client_request_id' => ['required', 'uuid'],
            // "max" on strings counts Unicode characters (mb_strlen), like Pixelforge.
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['sometimes', 'array', 'list', 'max:'.self::MAX_REFERENCE_IMAGES],
            // Passed through to Pixelforge, never fetched by this app.
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.max' => 'The prompt may not be longer than 4,000 characters.',
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.required' => 'Enter an image URL or remove this field.',
            'reference_images.*.url' => 'Reference images must be http:// or https:// URLs.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                    $validator->errors()->add((string) $field, "Unknown field \"{$field}\".");
                }
            },
        ];
    }
}
