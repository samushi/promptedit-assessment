<?php

declare(strict_types=1);

namespace App\Domain\Reports\Providers;

use App\Domain\User\Models\User;
use App\Support\AbstractServiceProvider;
use Illuminate\Support\Facades\Gate;

class ReportsServiceProvider extends AbstractServiceProvider
{
    public function setDomain(): string
    {
        return 'Reports';
    }

    public function boot(): void
    {
        parent::boot();

        // Operators only (users.is_admin, granted with `php artisan users:make-admin`).
        Gate::define('view-admin-report', fn (User $user): bool => $user->is_admin === true);
    }
}
