<?php

use Illuminate\Support\Facades\Route;
use Modules\Discover\Http\Controllers\Admin\DiscoverCategoryController;
use Modules\Discover\Http\Controllers\Admin\DiscoverCityController;
use Modules\Discover\Http\Controllers\Admin\DiscoverEventController;
use Modules\Discover\Http\Controllers\Admin\DiscoverOrganizerController;
use Modules\Discover\Http\Controllers\Admin\DiscoverSettingController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('discover-settings', [DiscoverSettingController::class, 'show']);
        Route::put('discover-settings', [DiscoverSettingController::class, 'update']);

        Route::patch('discover-categories/{discover_category}/status', [DiscoverCategoryController::class, 'status']);
        Route::apiResource('discover-categories', DiscoverCategoryController::class);

        Route::patch('discover-cities/{discover_city}/status', [DiscoverCityController::class, 'status']);
        Route::apiResource('discover-cities', DiscoverCityController::class);

        Route::get('discover-organizers', [DiscoverOrganizerController::class, 'index']);
        Route::get('discover-organizers/{discover_organizer}', [DiscoverOrganizerController::class, 'show']);
        Route::patch('discover-organizers/{discover_organizer}/review', [DiscoverOrganizerController::class, 'review']);

        // POST for update: multipart (the cover).
        Route::get('discover-events', [DiscoverEventController::class, 'index']);
        Route::post('discover-events', [DiscoverEventController::class, 'store']);
        Route::get('discover-events/{event}', [DiscoverEventController::class, 'show']);
        Route::post('discover-events/{event}', [DiscoverEventController::class, 'update']);
        Route::patch('discover-events/{event}/review', [DiscoverEventController::class, 'review']);
        Route::patch('discover-events/{event}/status', [DiscoverEventController::class, 'status']);
        Route::delete('discover-events/{event}', [DiscoverEventController::class, 'destroy']);
    });
});
