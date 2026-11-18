<?php

declare(strict_types=1);

namespace App\Domain\Generation\Commands;

use App\Domain\Generation\Actions\ReconcileGenerationsAction;
use Illuminate\Console\Command;

final class ReconcileGenerationsCommand extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Recover generations whose webhooks, jobs or submissions went missing';

    public function handle(): int
    {
        $result = ReconcileGenerationsAction::run();

        $this->components->info(sprintf(
            'Polling %d, resubmitting %d, abandoned %d, re-processing %d webhook events.',
            $result['synced'],
            $result['resubmitted'],
            $result['abandoned'],
            $result['events'],
        ));

        return self::SUCCESS;
    }
}
