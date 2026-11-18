<?php

declare(strict_types=1);

namespace App\Domain\Generation\Middleware;

use App\Domain\Generation\Exceptions\InvalidWebhookSignature;
use App\Domain\Generation\Services\PixelforgeWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Pixelforge webhooks by their HMAC signature, computed over the
 * exact raw body (before anything decodes it). The route has no session or
 * CSRF, so this is its only gate.
 */
final class VerifyPixelforgeSignature
{
    public function __construct(private readonly PixelforgeWebhookVerifier $verifier) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->verifier->verify($request->getContent(), $request->header('Pixelforge-Signature'));
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Rejected a Pixelforge webhook.', ['reason' => $e->getMessage()]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }
}
