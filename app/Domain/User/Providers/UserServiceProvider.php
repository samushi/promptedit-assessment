<?php

declare(strict_types=1);

namespace App\Domain\User\Providers;

use App\Domain\User\Commands\MakeAdminCommand;
use App\Support\AbstractServiceProvider;

class UserServiceProvider extends AbstractServiceProvider
{
    public function setDomain(): string
    {
        return 'User';
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([MakeAdminCommand::class]);
        }
    }
}
