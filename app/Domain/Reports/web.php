<?php

declare(strict_types=1);

use App\Domain\Reports\Controllers\AdminReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports domain: operator pages (loaded in the "web" group)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'can:view-admin-report'])->group(function () {
    Route::get('admin/report', AdminReportController::class)->name('admin.report');
});
