<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Helpers\DomainHelpers;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class for a domain's service provider. Loads the domain's routes:
 *
 *  - web.php: Inertia pages, in the "web" group (session, cookies, CSRF);
 *  - api.php: stateless endpoints such as provider webhooks, in the "api" group
 *    (no session or CSRF), so they must authenticate requests themselves.
 */
abstract class AbstractServiceProvider extends ServiceProvider
{
    /**
     * The domain's folder name under app/Domain, e.g. "Generation".
     */
    abstract public function setDomain(): string;

    public function boot(): void
    {
        $this->loadDomainRoutes();
    }

    protected function loadDomainRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $path = DomainHelpers::domainPath($this->setDomain());

        if (is_file($path.'/web.php')) {
            Route::middleware('web')->group($path.'/web.php');
        }

        if (is_file($path.'/api.php')) {
            Route::middleware('api')->group($path.'/api.php');
        }
    }
}
