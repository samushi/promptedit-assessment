<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\RecoveryOutcome;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Support\Actions\ActionFactory;

/**
 * Brings one generation up to date when its webhooks or jobs may have been
 * lost. Used by the follow-up checks queued when Pixelforge accepts a
 * generation, by the history page's repair of rows that look stuck, and by the
 * pixelforge:reconcile sweep.
 *
 *  - accepted (queued or processing): ask Pixelforge (GET, authoritative);
 *  - pending with no submission activity: resubmit with the same Idempotency-Key
 *    (a submission job that is still alive holds its unique lock, so this is a no-op);
 *  - pending and never confirmed for 24 hours: close it at $0.
 */
class RecoverGenerationAction extends ActionFactory
{
    /** Accepted generations nobody has heard about for this long look stuck. */
    public const int SYNC_AFTER_SECONDS = 60;

    /** Pending generations with no submission activity for this long are resubmitted. */
    public const int RESUBMIT_AFTER_SECONDS = 180;

    /** After this, a submission Pixelforge never confirmed is given up (it is $0). */
    public const int GIVE_UP_AFTER_HOURS = 24;

    public function __construct(private readonly GenerationRepository $generations) {}

    public function handle(string $generationId): RecoveryOutcome
    {
        $generation = $this->generations->find($generationId);

        if ($generation === null || $generation->status->isTerminal()) {
            return RecoveryOutcome::FINAL;
        }

        if ($generation->provider_generation_id !== null) {
            $generation = SyncGenerationAction::run($generation);

            return $generation->status->isTerminal() ? RecoveryOutcome::FINAL : RecoveryOutcome::IN_PROGRESS;
        }

        if ($generation->created_at->lessThan(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            // If Pixelforge did create it after all, a later webhook still wins (see GenerationStatus).
            RejectGenerationAction::run($generation, 'submission_unconfirmed', 'We could not confirm this generation with Pixelforge.');

            return RecoveryOutcome::ABANDONED;
        }

        if (self::looksStuck($generation)) {
            SubmitGenerationJob::dispatch($generation->id);

            return RecoveryOutcome::RESUBMITTED;
        }

        return RecoveryOutcome::WAITING;
    }

    /**
     * Whether an unfinished generation has been quiet for longer than it should
     * be. Cheap (no queries), so the history page can ask on every poll.
     */
    public static function looksStuck(Generation $generation): bool
    {
        if ($generation->status->isTerminal()) {
            return false;
        }

        $quietFor = $generation->provider_generation_id !== null
            ? self::SYNC_AFTER_SECONDS
            : self::RESUBMIT_AFTER_SECONDS;

        return $generation->updated_at->lessThan(now()->subSeconds($quietFor));
    }
}
