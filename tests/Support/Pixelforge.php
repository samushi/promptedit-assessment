<?php

namespace Tests\Support;

use App\Domain\Generation\Models\Generation;
use Illuminate\Support\Str;

/**
 * Payloads shaped exactly like the Pixelforge API reference.
 */
final class Pixelforge
{
    public const string WEBHOOK_SECRET = 'whsec_test_secret';

    /** @var array<string, string> */
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** Usage from the docs' succeeded example: billed at exactly 0.305510000. */
    public const array USAGE = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ];

    /**
     * @return array<string, mixed> GET /v1/pricing
     */
    public static function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * A generation object as Pixelforge returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(string $status = 'queued', array $overrides = []): array
    {
        $base = [
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];

        $progress = match ($status) {
            'processing' => ['started_at' => '2026-11-04T14:03:13Z'],
            'succeeded' => [
                'started_at' => '2026-11-04T14:03:13Z',
                'completed_at' => '2026-11-04T14:03:29Z',
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc',
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'usage' => self::USAGE,
            ],
            'failed' => [
                'started_at' => '2026-11-04T14:03:13Z',
                'completed_at' => '2026-11-04T14:03:20Z',
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
                ],
            ],
            default => [],
        };

        return array_replace($base, $progress, $overrides);
    }

    /**
     * The generation object Pixelforge would report for one of our rows.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generationFor(Generation $generation, string $status, array $overrides = []): array
    {
        return self::generation($status, [
            'id' => $generation->provider_generation_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id],
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function event(string $type, array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.Str::upper(Str::random(26)),
            'object' => 'event',
            'type' => $type,
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * Raw body and headers of a signed webhook delivery.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: array<string, string>}
     */
    public static function signedDelivery(array $event, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();

        return [$body, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Pixelforge-Webhooks/1.0',
            'Pixelforge-Event-Id' => (string) ($event['id'] ?? ''),
            'Pixelforge-Event-Type' => (string) ($event['type'] ?? ''),
            'Pixelforge-Delivery-Attempt' => '1',
            'Pixelforge-Signature' => self::signature($body, $timestamp, $secret),
        ]];
    }

    public static function signature(string $body, int $timestamp, string $secret = self::WEBHOOK_SECRET): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /**
     * @return array<string, mixed> a Pixelforge error body
     */
    public static function error(string $code, string $message = 'Request failed.'): array
    {
        return ['error' => ['type' => 'error', 'code' => $code, 'message' => $message]];
    }
}
