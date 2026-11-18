<?php

namespace App\Domain\Generation\Enums;

/**
 * Documented Pixelforge error codes. We branch on the code, not the HTTP status.
 * Anything not listed here (timeouts, bare 5xx, unknown codes) is treated as an
 * ambiguous outcome: the request may or may not have created a generation.
 */
enum PixelforgeError: string
{
    case INVALID_API_KEY = 'invalid_api_key';
    case VALIDATION_FAILED = 'validation_failed';
    case CONTENT_POLICY_VIOLATION = 'content_policy_violation';
    case NOT_FOUND = 'not_found';
    case IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';
    case RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    case CONCURRENCY_LIMIT_EXCEEDED = 'concurrency_limit_exceeded';
    case DAILY_LIMIT_EXCEEDED = 'daily_limit_exceeded';
    case OVERLOADED = 'overloaded';
    case DATE_RANGE_TOO_LONG = 'date_range_too_long';

    /**
     * Temporary conditions where Pixelforge created nothing and a retry with the
     * same Idempotency-Key (after Retry-After) is expected to succeed eventually.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::RATE_LIMIT_EXCEEDED, self::CONCURRENCY_LIMIT_EXCEEDED, self::OVERLOADED], true);
    }

    /** Errors that mean our configuration or code is wrong, not the user's request. */
    public function isOurFault(): bool
    {
        return in_array($this, [self::INVALID_API_KEY, self::IDEMPOTENCY_KEY_REUSED], true);
    }

    /** Shown to the user once we stop trying; every case means nothing was billed. */
    public function userMessage(): string
    {
        return match ($this) {
            self::CONTENT_POLICY_VIOLATION => 'Pixelforge rejected this prompt under its content policy.',
            self::VALIDATION_FAILED => 'Pixelforge rejected the request as invalid.',
            self::DAILY_LIMIT_EXCEEDED => 'Pixelforge\'s daily generation quota is used up. It resets at midnight UTC.',
            self::RATE_LIMIT_EXCEEDED => 'Pixelforge kept rate-limiting our requests. Please try again in a minute.',
            self::CONCURRENCY_LIMIT_EXCEEDED => 'Pixelforge stayed at capacity for too long. Please try again.',
            self::OVERLOADED => 'Pixelforge is overloaded right now. Please try again in a minute.',
            self::INVALID_API_KEY, self::IDEMPOTENCY_KEY_REUSED, self::NOT_FOUND, self::DATE_RANGE_TOO_LONG => 'Image generation is temporarily unavailable. Our team has been notified.',
        };
    }

    /** Shown while we keep retrying a still-pending submission. */
    public function retryingMessage(): ?string
    {
        return match ($this) {
            self::CONCURRENCY_LIMIT_EXCEEDED => 'Waiting for a free Pixelforge slot (at most 3 generations run at once)…',
            self::RATE_LIMIT_EXCEEDED => 'Pixelforge is rate-limiting requests. Retrying shortly…',
            self::OVERLOADED => 'Pixelforge is overloaded. Retrying shortly…',
            default => null,
        };
    }
}
