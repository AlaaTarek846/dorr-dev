<?php

use Illuminate\Support\Facades\Route;

// Users on the mobile app (the only participants enabled today — config chat.enabled_participants).
Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';
    });
});
