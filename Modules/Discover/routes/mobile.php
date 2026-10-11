<?php

use Illuminate\Support\Facades\Route;
use Modules\Discover\Http\Controllers\DiscoverController;
use Modules\Discover\Http\Controllers\OrganizerController;

// DORR Discover in the mobile app (spec 169–182).
Route::middleware('locale')->prefix('mobile/v1/discover')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        Route::get('home', [DiscoverController::class, 'home']);
        Route::get('events', [DiscoverController::class, 'index']);
        Route::get('travel', [DiscoverController::class, 'travel']);
        Route::post('ask', [DiscoverController::class, 'ask'])->middleware('throttle:20,1');
        Route::get('categories', [DiscoverController::class, 'categories']);
        Route::get('cities', [DiscoverController::class, 'cities']);
        Route::get('interests', [DiscoverController::class, 'interests']);
        Route::get('preferences', [DiscoverController::class, 'preferences']);
        Route::put('preferences', [DiscoverController::class, 'savePreferences']);
        Route::get('follows', [DiscoverController::class, 'follows']);
        Route::post('follows', [DiscoverController::class, 'follow']);
        Route::delete('follows/{follow}', [DiscoverController::class, 'unfollow'])->whereNumber('follow');

        Route::get('organizer', [OrganizerController::class, 'show']);
        Route::post('organizer', [OrganizerController::class, 'apply']);
        Route::post('organizer/events', [OrganizerController::class, 'store']);
        Route::post('organizer/events/{event}', [OrganizerController::class, 'update']);
        Route::post('organizer/events/{event}/status', [OrganizerController::class, 'status']);

        Route::get('events/{event}', [DiscoverController::class, 'show']);
        Route::post('events/{event}/interest', [DiscoverController::class, 'interest']);
        Route::delete('events/{event}/interest', [DiscoverController::class, 'uninterest']);
        Route::post('events/{event}/share', [DiscoverController::class, 'share']);
        Route::post('events/{event}/room', [DiscoverController::class, 'room']);
    });
});
