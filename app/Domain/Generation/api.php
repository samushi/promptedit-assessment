<?php

declare(strict_types=1);

use App\Domain\Generation\Controllers\PixelforgeWebhookController;
use App\Domain\Generation\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Generation domain: machine endpoints (loaded in the "api" group)
|--------------------------------------------------------------------------
|
| Called by Pixelforge, not a browser: no session, cookies or CSRF token.
| Every request is authenticated by its HMAC signature instead.
|
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
