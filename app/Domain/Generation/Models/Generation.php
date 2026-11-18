<?php

declare(strict_types=1);

namespace App\Domain\Generation\Models;

use App\Domain\Generation\Database\Factories\GenerationFactory;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Domain\User\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\ReadModel;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image the user asked for, and what it cost them.
 *
 * Status and money only change through ApplyProviderSnapshotAction and
 * RejectGenerationAction, under a row lock. Queries live in GenerationRepository.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $client_request_id
 * @property string $idempotency_key
 * @property string|null $provider_generation_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property array<string, mixed> $provider_request
 * @property BigDecimal $estimated_provider_cost_usd
 * @property BigDecimal $estimated_customer_cost_usd
 * @property BigDecimal $max_customer_cost_usd
 * @property BigDecimal|null $actual_provider_cost_usd
 * @property BigDecimal|null $actual_customer_cost_usd
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submission_attempts
 * @property int $submission_failures
 * @property bool $submission_ambiguous
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 */
#[UseFactory(GenerationFactory::class)]
class Generation extends ReadModel
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Pixelforge's signed URLs stop working ~24h after completion. */
    public function isImageExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'provider_request' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_provider_cost_usd' => MoneyCast::class,
            'estimated_customer_cost_usd' => MoneyCast::class,
            'max_customer_cost_usd' => MoneyCast::class,
            'actual_provider_cost_usd' => MoneyCast::class,
            'actual_customer_cost_usd' => MoneyCast::class,
            'submission_attempts' => 'integer',
            'submission_failures' => 'integer',
            'submission_ambiguous' => 'boolean',
            'image_url_expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
