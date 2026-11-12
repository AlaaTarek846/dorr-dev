<?php

use Illuminate\Support\Facades\Route;
use Modules\Marketing\Http\Controllers\PlatformCallbackController;

// The ad platforms' side (no user session): the OAuth redirect back and signed webhooks.
Route::prefix('marketing/v1')->middleware(['locale', 'throttle:120,1'])->group(function () {
    Route::get('oauth/{provider}/callback', [PlatformCallbackController::class, 'callback'])->name('marketing.oauth.callback');
    Route::get('webhooks/{provider}', [PlatformCallbackController::class, 'verify']);
    Route::post('webhooks/{provider}', [PlatformCallbackController::class, 'receive']);
});
