<?php

use Illuminate\Support\Facades\Route;

// The DORR user: the user SPA and the mobile app (like the AI module, the app calls user/v1).
Route::middleware(['locale', 'auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1'])
    ->prefix('user/v1/marketing')->name('marketing.user.')
    ->group(__DIR__.'/customer.php');
