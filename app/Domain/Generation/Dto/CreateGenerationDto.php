<?php

declare(strict_types=1);

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Support\DataObjects;

/**
 * A validated image request, from StoreGenerationRequest (or the live estimate,
 * which has no client request id yet).
 */
final class CreateGenerationDto extends DataObjects
{
    /**
     * @param  list<string>|null  $referenceImages  http(s) URLs; passed through, never fetched by us
     * @param  string|null  $clientRequestId  one id per form submission (double submits are one generation)
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $size,
        public readonly string $quality,
        public readonly ?array $referenceImages = null,
        public readonly ?string $clientRequestId = null,
    ) {}

    public function size(): ImageSize
    {
        return ImageSize::from($this->size);
    }

    public function quality(): ImageQuality
    {
        return ImageQuality::from($this->quality);
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        return $this->referenceImages ?? [];
    }

    /** Pixelforge counts Unicode characters (code points), not bytes. */
    public function promptCharacters(): int
    {
        return mb_strlen($this->prompt, 'UTF-8');
    }

    /**
     * The exact JSON body for POST /v1/generations.
     *
     * Only documented fields are sent (Pixelforge rejects unknown ones), size and
     * quality are always explicit, and our id travels in metadata so webhooks can
     * be matched even before the POST response arrives.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata: array{generation_id: string}}
     */
    public function toProviderPayload(string $generationId): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size()->value,
            'quality' => $this->quality()->value,
        ];

        if ($this->referenceImages() !== []) {
            $payload['reference_images'] = $this->referenceImages();
        }

        $payload['metadata'] = ['generation_id' => $generationId];

        return $payload;
    }
}
