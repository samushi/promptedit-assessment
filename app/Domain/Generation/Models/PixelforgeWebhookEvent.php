<?php

declare(strict_types=1);

namespace App\Domain\Generation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified Pixelforge webhook delivery, deduplicated by its event id.
 * Queries live in PixelforgeWebhookEventRepository.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property array<string, mixed> $payload
 * @property int|null $delivery_attempt
 * @property int $processing_attempts
 * @property string|null $last_error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
#[Unguarded]
#[WithoutTimestamps]
class PixelforgeWebhookEvent extends Model
{
    /**
     * The generation object the event carries (data.object).
     *
     * @return array<mixed>|null
     */
    public function generationObject(): ?array
    {
        $object = data_get($this->payload, 'data.object');

        return is_array($object) ? $object : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'processing_attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
