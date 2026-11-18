<?php

declare(strict_types=1);

use App\Domain\Generation\Controllers\GenerationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Generation domain: pages (loaded in the "web" group by the domain provider)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('generations', [GenerationsController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationsController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::post('generations/estimate', [GenerationsController::class, 'estimate'])
        ->middleware('throttle:240,1')
        ->name('generations.estimate');
});
