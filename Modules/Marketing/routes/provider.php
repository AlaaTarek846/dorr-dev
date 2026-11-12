<?php

use Illuminate\Support\Facades\Route;

// The provider SPA.
Route::middleware(['locale', 'auth:provider_api', 'throttle:240,1'])
    ->prefix('provider/v1/marketing')->name('marketing.provider.')
    ->group(__DIR__.'/customer.php');
