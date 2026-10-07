<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AiSiteServeController;

// Customer-generated sites. Loaded WITHOUT any middleware group on purpose
// (no cookies, no session, no CSRF): the content is untrusted, see
// AiSiteServeController. Domain or prefix is chosen in RouteServiceProvider.
Route::middleware('throttle:240,1')->get('{slug}/{path?}', [AiSiteServeController::class, 'show'])
    ->where('slug', '[A-Za-z0-9]{40}')
    ->where('path', '.*')
    ->name('show');
