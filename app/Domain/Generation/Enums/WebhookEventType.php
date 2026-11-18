<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

use App\Domain\Generation\WebhookEvents\GenerationWebhookEvent;

/**
 * Pixelforge webhook event types, and which handler processes each one.
 */
enum WebhookEventType: string
{
    /** "Send test event" in the dashboard: acknowledged, nothing to do. */
    case PING = 'ping';

    case GENERATION_PROCESSING = 'generation.processing';
    case GENERATION_SUCCEEDED = 'generation.succeeded';
    case GENERATION_FAILED = 'generation.failed';

    /**
     * @return class-string<GenerationWebhookEvent>|null null when the event needs no work
     */
    public function handler(): ?string
    {
        return match ($this) {
            self::PING => null,
            self::GENERATION_PROCESSING, self::GENERATION_SUCCEEDED, self::GENERATION_FAILED => GenerationWebhookEvent::class,
        };
    }
}
