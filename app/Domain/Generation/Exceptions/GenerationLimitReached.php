<?php

declare(strict_types=1);

namespace App\Domain\Generation\Exceptions;

use RuntimeException;

/**
 * A daily generation cap was reached (abuse guard for the public demo). The
 * days are UTC days, like Pixelforge's own daily quota.
 */
final class GenerationLimitReached extends RuntimeException
{
    public static function forUser(int $limit): self
    {
        return new self("You've reached today's limit of {$limit} generations. It resets at midnight UTC.");
    }

    public static function forEveryone(): self
    {
        return new self('This demo has reached its generation limit for today. It resets at midnight UTC.');
    }
}
