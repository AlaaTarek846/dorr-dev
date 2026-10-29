<?php

use Illuminate\Support\Facades\Route;

// Users on the mobile app (the only participants enabled today — config chat.enabled_participants).
Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    // Files of educational / work groups: a signed link for one viewer, checked on every download
    // (docs/groups_tasks.md GR-008). No token header — images and videos load straight from it.
    Route::get('chat/media/{media}', \Modules\Chat\Http\Controllers\General\ProtectedMediaController::class)
        ->middleware(['signed', 'throttle:600,1'])->whereUuid('media')->name('chat.media.protected');
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';
    });
});
