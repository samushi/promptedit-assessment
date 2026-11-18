<?php

namespace App\Domain\Generation\Services;

use App\Domain\Generation\Dto\ProviderGeneration;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Exceptions\PixelforgeException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Pixelforge API.
 *
 * Every call makes exactly one request: retries (and their pacing against the
 * account-wide 60 requests/minute limit) belong to the queued jobs.
 */
final class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * POST /v1/generations. Replaying the same key with the same body returns the
     * existing generation (202, Idempotent-Replayed) instead of creating another.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $payload, string $idempotencyKey): ProviderGeneration
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload));

        return $this->generation($response);
    }

    /**
     * GET /v1/generations/{id}: the authoritative current state.
     *
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerGenerationId): ProviderGeneration
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->get('generations/'.rawurlencode($providerGenerationId)));

        return $this->generation($response);
    }

    /**
     * GET /v1/usage for the API key we call it with (inclusive UTC dates).
     *
     * @return array<mixed>
     *
     * @throws PixelforgeException
     */
    public function usage(string $startDate, string $endDate): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]));

        return $this->json($response);
    }

    /**
     * GET /v1/pricing is public: no API key is sent.
     *
     * @return array<mixed>
     *
     * @throws PixelforgeException
     */
    public function pricing(): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)->acceptJson()->timeout(5)->connectTimeout(3)->get('pricing');
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $this->json($response);
    }

    /**
     * @param  Closure(PendingRequest): Response  $request
     *
     * @throws PixelforgeException
     */
    private function send(Closure $request): Response
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw PixelforgeException::notConfigured();
        }

        $http = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(5);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @throws PixelforgeException
     */
    private function generation(Response $response): ProviderGeneration
    {
        try {
            return ProviderGeneration::fromArray($this->json($response));
        } catch (InvalidPixelforgePayload $e) {
            // A 2xx we can't read may still mean "created": treat it as ambiguous.
            throw PixelforgeException::unreadableResponse($response, $e);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws PixelforgeException
     */
    private function json(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw PixelforgeException::unreadableResponse($response, new InvalidPixelforgePayload('Response body is not a JSON object.'));
        }

        return $data;
    }
}
