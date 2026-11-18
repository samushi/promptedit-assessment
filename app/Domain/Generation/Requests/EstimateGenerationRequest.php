<?php

declare(strict_types=1);

namespace App\Domain\Generation\Requests;

use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Support\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Live estimate while the user types. Same normalisation as the real submit
 * (the prompt is trimmed by middleware), but nothing here is required to be
 * complete: an empty prompt or a half-typed URL still gets a price.
 */
class EstimateGenerationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.StoreGenerationRequest::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['sometimes', 'array', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function toDto(): CreateGenerationDto
    {
        // Only the number of references affects the price; blank fields aren't sent.
        $references = array_filter($this->array('reference_images'), fn (mixed $url): bool => is_string($url) && $url !== '');

        return CreateGenerationDto::fromArray([
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => array_values($references),
        ]);
    }
}
