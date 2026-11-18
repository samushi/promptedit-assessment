<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * What one recovery check did for a generation (see RecoverGenerationAction).
 */
enum RecoveryOutcome: string
{
    /** Nothing left to do: the generation is final (or no longer exists). */
    case FINAL = 'final';

    /** Pixelforge still reports it queued or processing, or couldn't be reached. */
    case IN_PROGRESS = 'in_progress';

    /** Pending and its submission is still being retried: left alone. */
    case WAITING = 'waiting';

    /** Pending with no submission activity: queued again with the same Idempotency-Key. */
    case RESUBMITTED = 'resubmitted';

    /** Never confirmed by Pixelforge within the give-up window: closed at $0. */
    case ABANDONED = 'abandoned';

    public function isFinal(): bool
    {
        return $this === self::FINAL || $this === self::ABANDONED;
    }
}
