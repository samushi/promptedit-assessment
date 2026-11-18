<?php

namespace App\Providers;

use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Only operators (users.is_admin) may open the Horizon dashboard, in every
     * environment: unlike Horizon's default, local is not open to everyone,
     * because a tunnel can expose a local app publicly.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Horizon only sees Redis queues. In production the jobs run on Laravel
     * Cloud's managed queue (QUEUE_CONNECTION=cloud), so the dashboard is
     * closed there (its API would try to reach a Redis that doesn't exist).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_admin === true
            && config('queue.default') === 'redis');
    }
}
