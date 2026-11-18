<?php

namespace App\Domain\Generation\Enums;

use InvalidArgumentException;

enum GenerationStatus: string
{
    /** Saved locally; Pixelforge has not (yet) confirmed accepting it. */
    case PENDING = 'pending';

    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    /** Pixelforge refused it before creating anything, so nothing is billed. */
    case REJECTED = 'rejected';

    /**
     * Map a status reported by Pixelforge (it never reports pending/rejected).
     */
    public static function fromProvider(string $status): self
    {
        $parsed = self::tryFrom($status);

        if (! in_array($parsed, [self::QUEUED, self::PROCESSING, self::SUCCEEDED, self::FAILED], true)) {
            throw new InvalidArgumentException("Unknown Pixelforge generation status [{$status}].");
        }

        return $parsed;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::REJECTED], true);
    }

    /**
     * Whether a provider snapshot in status $next may move this generation forward.
     *
     * Statuses only move forward (queued -> processing -> succeeded|failed), so a
     * duplicate or out-of-order event can never regress a generation, and the
     * provider's terminal states are final. "Rejected" is only our inference that
     * Pixelforge created nothing, so real provider evidence is allowed to override it.
     */
    public function canAdvanceTo(self $next): bool
    {
        if ($this === self::SUCCEEDED || $this === self::FAILED) {
            return false;
        }

        return $next->providerRank() > $this->providerRank();
    }

    private function providerRank(): int
    {
        return match ($this) {
            self::PENDING, self::REJECTED => 0,
            self::QUEUED => 1,
            self::PROCESSING => 2,
            self::SUCCEEDED, self::FAILED => 3,
        };
    }
}
