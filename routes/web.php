<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    // The app's home is the generate page (app/Domain/Generation/web.php).
    Route::redirect('dashboard', '/generations')->name('dashboard');
});

// Every other route belongs to a domain: app/Domain/*/web.php and api.php.
