<?php

declare(strict_types=1);

use App\Domain\Billing\Controllers\CreditsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing domain: credits (loaded in the "web" group by the domain provider)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('credits', [CreditsController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('credits.store');
});
