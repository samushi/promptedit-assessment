<?php

namespace App\Domain\Generation\Services;

use App\Domain\Generation\Dto\PricingConfig;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Exceptions\PixelforgeException;
use App\Domain\Generation\Exceptions\PricingUnavailable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Current Pixelforge pricing for estimates, cached. Rates are never hardcoded.
 *
 * This is never used to bill: each generation is billed at the rates
 * Pixelforge snapshotted on it when it was created.
 */
final class PixelforgePricing
{
    private const string CACHE_KEY = 'pixelforge:pricing';

    private const string LAST_GOOD_KEY = 'pixelforge:pricing:last-good';

    private const int FRESH_SECONDS = 600;

    private const int LAST_GOOD_SECONDS = 86_400;

    /** While Pixelforge is down, serve the last good copy without refetching on every request. */
    private const int OUTAGE_RETRY_SECONDS = 60;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly Cache $cache,
    ) {}

    /**
     * @throws PricingUnavailable when Pixelforge is unreachable and there is no recent copy
     */
    public function current(): PricingConfig
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PricingConfig::fromArray($cached);
        }

        try {
            $pricing = PricingConfig::fromArray($this->client->pricing());
        } catch (PixelforgeException|InvalidPixelforgePayload $e) {
            return $this->lastGood($e);
        }

        $this->cache->put(self::CACHE_KEY, $pricing->toArray(), self::FRESH_SECONDS);
        $this->cache->put(self::LAST_GOOD_KEY, $pricing->toArray(), self::LAST_GOOD_SECONDS);

        return $pricing;
    }

    public function isAvailable(): bool
    {
        try {
            $this->current();

            return true;
        } catch (PricingUnavailable) {
            return false;
        }
    }

    private function lastGood(PixelforgeException|InvalidPixelforgePayload $error): PricingConfig
    {
        $lastGood = $this->cache->get(self::LAST_GOOD_KEY);

        if (! is_array($lastGood)) {
            Log::error('Pixelforge pricing is unavailable and there is no cached copy.', ['error' => $error->getMessage()]);

            throw new PricingUnavailable('Pixelforge pricing is temporarily unavailable.', previous: $error);
        }

        Log::warning('Pixelforge pricing fetch failed; using the last known pricing.', ['error' => $error->getMessage()]);
        $this->cache->put(self::CACHE_KEY, $lastGood, self::OUTAGE_RETRY_SECONDS);

        return PricingConfig::fromArray($lastGood);
    }
}
