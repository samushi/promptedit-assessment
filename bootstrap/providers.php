<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Support\DomainServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    // Discovers app/Domain/*/Providers and app/Domain/*/Database/Migrations.
    DomainServiceProvider::class,
];
