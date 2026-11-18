<?php

namespace App\Domain\Generation\Dto;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * A Pixelforge generation object, from a POST/GET response or a webhook's data.object.
 */
final readonly class ProviderGeneration
{
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        /** Our generations.id, echoed back in metadata.generation_id. */
        public ?string $localGenerationId,
        public ?TokenRates $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || ! str_starts_with($id, 'gen_') || strlen($id) > 255) {
            throw new InvalidPixelforgePayload('Generation id is missing or invalid.');
        }

        try {
            $status = GenerationStatus::fromProvider(is_string($data['status'] ?? null) ? $data['status'] : '');
        } catch (InvalidArgumentException $e) {
            throw new InvalidPixelforgePayload($e->getMessage(), previous: $e);
        }

        $usage = is_array($data['usage'] ?? null) ? TokenUsage::fromArray($data['usage']) : null;
        $completedAt = self::timestamp($data, 'completed_at');

        // We can't bill a success without usage, or report it without its completion day.
        if ($status === GenerationStatus::SUCCEEDED && ($usage === null || $completedAt === null)) {
            throw new InvalidPixelforgePayload('A succeeded generation must include usage and completed_at.');
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        return new self(
            id: $id,
            status: $status,
            localGenerationId: self::localGenerationId($data['metadata'] ?? null),
            rates: is_array($data['rates'] ?? null) ? TokenRates::fromArray($data['rates']) : null,
            usage: $usage,
            imageUrl: is_string($data['image_url'] ?? null) ? $data['image_url'] : null,
            imageUrlExpiresAt: self::timestamp($data, 'image_url_expires_at'),
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            errorMessage: is_string($error['message'] ?? null) ? $error['message'] : null,
            createdAt: self::timestamp($data, 'created_at'),
            startedAt: self::timestamp($data, 'started_at'),
            completedAt: $completedAt,
        );
    }

    /**
     * Only accept a well-formed UUID: it's used to look up a uuid column.
     */
    private static function localGenerationId(mixed $metadata): ?string
    {
        $id = is_array($metadata) ? ($metadata['generation_id'] ?? null) : null;

        return is_string($id) && Str::isUuid($id) ? strtolower($id) : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function timestamp(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidPixelforgePayload("[{$key}] must be an ISO 8601 string.");
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $e) {
            throw new InvalidPixelforgePayload("[{$key}] is not a valid timestamp.", previous: $e);
        }
    }
}
