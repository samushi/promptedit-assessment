<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Helpers\DomainHelpers;
use Illuminate\Support\ServiceProvider;

/**
 * Wires every domain in: registers each domain's service provider (which loads
 * its routes) and each domain's Database/Migrations folder.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (DomainHelpers::providers() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(DomainHelpers::migrationPaths());
    }
}
