<?php

namespace App\Domain\Generation\Services;

use App\Domain\Generation\Exceptions\InvalidWebhookSignature;
use RuntimeException;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix timestamp>,v1=<hex HMAC-SHA256>"
 * where the HMAC is computed over "<timestamp>.<raw request body>" with the
 * webhook signing secret of this environment's API key.
 */
final class PixelforgeWebhookVerifier
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * Must run on the raw body, before any JSON decoding.
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(string $rawBody, ?string $header, ?int $now = null): void
    {
        if ($this->secret === null || $this->secret === '') {
            // Our misconfiguration: fail loudly (500) so Pixelforge keeps retrying.
            throw new RuntimeException('PIXELFORGE_WEBHOOK_SECRET is not configured.');
        }

        [$timestamp, $signatures] = $this->parse($header);

        // Rejects replays of old deliveries (each attempt is signed afresh).
        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            throw InvalidWebhookSignature::timestampOutsideTolerance();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw InvalidWebhookSignature::mismatch();
    }

    /**
     * @return array{string, list<string>} the timestamp exactly as signed, and every v1 signature
     */
    private function parse(?string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && $timestamp === null && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw InvalidWebhookSignature::malformedHeader();
        }

        return [$timestamp, $signatures];
    }
}
