<?php

declare(strict_types=1);

namespace App\Domain\Reports\Dto;

use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;

/**
 * GET /v1/usage: what Pixelforge billed this API key, per UTC day of completion.
 */
final readonly class ProviderUsageReport
{
    /**
     * @param  array<string, ProviderUsageDay>  $days  keyed by YYYY-MM-DD
     */
    public function __construct(
        public array $days,
        public ProviderUsageDay $total,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! is_array($data['data'] ?? null) || ! is_array($data['total'] ?? null)) {
            throw new InvalidPixelforgePayload('Usage report must contain data and total.');
        }

        $days = [];

        foreach ($data['data'] as $row) {
            $day = ProviderUsageDay::fromArray(is_array($row) ? $row : []);
            $days[(string) $day->date] = $day;
        }

        return new self($days, ProviderUsageDay::fromArray($data['total'], isTotal: true));
    }

    public function day(string $date): ?ProviderUsageDay
    {
        return $this->days[$date] ?? null;
    }
}
