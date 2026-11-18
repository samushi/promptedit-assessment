<?php

declare(strict_types=1);

namespace App\Domain\Generation\Providers;

use App\Domain\Generation\Commands\ReconcileGenerationsCommand;
use App\Domain\Generation\Services\PixelforgeClient;
use App\Domain\Generation\Services\PixelforgeWebhookVerifier;
use App\Support\AbstractServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;

class GenerationServiceProvider extends AbstractServiceProvider
{
    public function setDomain(): string
    {
        return 'Generation';
    }

    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            baseUrl: Config::string('services.pixelforge.base_url'),
            apiKey: $this->optionalConfig('services.pixelforge.api_key'),
            timeoutSeconds: Config::integer('services.pixelforge.timeout'),
        ));

        $this->app->bind(PixelforgeWebhookVerifier::class, fn (): PixelforgeWebhookVerifier => new PixelforgeWebhookVerifier(
            secret: $this->optionalConfig('services.pixelforge.webhook_secret'),
            toleranceSeconds: Config::integer('services.pixelforge.webhook_tolerance'),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileGenerationsCommand::class]);
        }

        // Safety net for missed webhooks, lost jobs and unconfirmed submissions.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = $this->reconcileSchedule();

            if ($cron !== null) {
                $schedule->command(ReconcileGenerationsCommand::class)
                    ->cron($cron)
                    ->withoutOverlapping()
                    ->onOneServer();
            }
        });
    }

    /**
     * When the recurring sweep runs: every minute locally, off in production
     * unless PIXELFORGE_RECONCILE_SCHEDULE sets a cron expression. A frequent
     * schedule would wake a scale-to-zero app and its database around the
     * clock, and in production the follow-up checks queued for each generation
     * (and the history page's repair) already recover lost webhooks.
     */
    private function reconcileSchedule(): ?string
    {
        $configured = config('services.pixelforge.reconcile_schedule');

        if ($configured === false || $configured === '') {
            return null;
        }

        if (is_string($configured)) {
            return $configured;
        }

        return $this->app->isProduction() ? null : '* * * * *';
    }

    private function optionalConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
