<?php

namespace App\Domain\Generation\Exceptions;

use App\Domain\Generation\Enums\PixelforgeError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A Pixelforge API call that did not succeed.
 *
 * $error is the documented error code, or null when the outcome is ambiguous:
 * a timeout, a connection failure, a bare 5xx or an unreadable response. Then
 * we can't know whether a POST created a generation, so it's retried with the
 * same Idempotency-Key and never treated as "nothing was created".
 */
final class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?PixelforgeError $error,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        return new self(
            message: is_string($message) ? $message : "Pixelforge returned HTTP {$response->status()}.",
            status: $response->status(),
            error: is_string($code) ? PixelforgeError::tryFrom($code) : null,
            retryAfter: self::retryAfter($response),
        );
    }

    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self('Could not reach Pixelforge (timeout or connection error).', 0, null, null, $exception);
    }

    public static function unreadableResponse(Response $response, Throwable $previous): self
    {
        return new self("Pixelforge returned an unreadable response (HTTP {$response->status()}).", $response->status(), null, null, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('PIXELFORGE_API_KEY is not configured.', 0, PixelforgeError::INVALID_API_KEY);
    }

    /** We don't know whether the request had any effect. */
    public function isAmbiguous(): bool
    {
        return $this->error === null;
    }

    /** Pixelforge refused for a reason a retry won't change. */
    public function isDefinitive(): bool
    {
        return $this->error !== null && ! $this->error->isRetryable();
    }

    /** Stored on the generation; "submission_unconfirmed" means the outcome is unknown. */
    public function errorCode(): string
    {
        return $this->error->value ?? 'submission_unconfirmed';
    }

    /** What the user sees once we give up. */
    public function userMessage(): string
    {
        return match ($this->error) {
            null => 'We could not confirm this generation with Pixelforge.',
            PixelforgeError::VALIDATION_FAILED => 'Pixelforge rejected the request: '.$this->getMessage(),
            default => $this->error->userMessage(),
        };
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        return ctype_digit($value) ? (int) $value : null;
    }
}
