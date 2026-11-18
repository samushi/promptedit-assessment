<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers;

use App\Support\AbstractServiceProvider;

class BillingServiceProvider extends AbstractServiceProvider
{
    public function setDomain(): string
    {
        return 'Billing';
    }
}
