<?php

namespace App\Domain\Generation\Database\Factories;

use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * Pixelforge's documented rates (USD per 1M tokens).
     *
     * @var array<string, string>
     */
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'client_request_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::PENDING,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => ImageSize::SQUARE,
            'quality' => ImageQuality::MEDIUM,
            'reference_images' => [],
            'provider_request' => function (array $attributes): array {
                $request = new CreateGenerationDto(
                    prompt: $attributes['prompt'],
                    size: $attributes['size'] instanceof ImageSize ? $attributes['size']->value : $attributes['size'],
                    quality: $attributes['quality'] instanceof ImageQuality ? $attributes['quality']->value : $attributes['quality'],
                    referenceImages: $attributes['reference_images'],
                );

                return $request->toProviderPayload($attributes['id']);
            },
            'estimated_provider_cost_usd' => '0.058305',
            'estimated_customer_cost_usd' => '0.07288125',
            'max_customer_cost_usd' => '0.0866875',
        ];
    }

    /** Accepted by Pixelforge and waiting in its queue. */
    public function queued(?string $providerGenerationId = null): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::QUEUED,
            'provider_generation_id' => $providerGenerationId ?? 'gen_'.Str::upper(Str::random(26)),
            'rates' => self::RATES,
            'accepted_at' => now()->subSeconds(5),
        ]);
    }

    public function processing(?string $providerGenerationId = null): static
    {
        return $this->queued($providerGenerationId)->state(fn (): array => [
            'status' => GenerationStatus::PROCESSING,
            'started_at' => now()->subSeconds(3),
        ]);
    }
}
