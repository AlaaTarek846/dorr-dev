<?php

use Illuminate\Support\Facades\Route;

// Users on the website (the Vue user SPA at /user) — the same chat API as the app, same rules.
Route::middleware('locale')->prefix('user/v1')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';
    });
});
