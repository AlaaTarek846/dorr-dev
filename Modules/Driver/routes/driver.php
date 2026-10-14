<?php

use Illuminate\Support\Facades\Route;
use Modules\Driver\Http\Controllers\Driver\DriverAuthController;
use Modules\Driver\Http\Controllers\Driver\OnboardingController;
use Modules\Driver\Http\Controllers\Driver\PresenceController;

// The driver app (docs/rides-plan.md §4.1): its own guard, its own prefix.
Route::middleware('locale')->prefix('driver/v1')->group(function () {
    Route::middleware(['guest:driver_api', 'throttle:10,1'])->group(function () {
        Route::post('auth/otp', [DriverAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [DriverAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [DriverAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:driver_api', 'ensure-phone-verified:driver_api', 'throttle:60,1', 'remember-locale'])->group(function () {
        Route::get('auth/me', [DriverAuthController::class, 'me']);
        Route::post('auth/logout', [DriverAuthController::class, 'logout']);

        Route::get('presence', [PresenceController::class, 'show']);
        Route::post('presence/online', [PresenceController::class, 'online']);
        Route::post('presence/offline', [PresenceController::class, 'offline']);
        Route::post('presence/pause', [PresenceController::class, 'pause']);
        Route::post('presence/last-ride', [PresenceController::class, 'lastRide']);
        Route::post('presence/gps', [PresenceController::class, 'gps']);

        // Signing up: details → vehicle → documents → review (the uploads have their own limit below).
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::get('onboarding/options', [OnboardingController::class, 'options']);
        Route::put('profile', [OnboardingController::class, 'updateProfile']);
        Route::post('onboarding/submit', [OnboardingController::class, 'submit']);
        Route::post('vehicles', [OnboardingController::class, 'storeVehicle']);
        Route::put('vehicles/{vehicle}', [OnboardingController::class, 'updateVehicle'])->whereNumber('vehicle');
    });

    Route::middleware(['auth:driver_api', 'ensure-phone-verified:driver_api', 'throttle:20,1'])->group(function () {
        Route::post('profile/avatar', [OnboardingController::class, 'avatar']);
        Route::post('documents', [OnboardingController::class, 'storeDocument']);
    });

    // Location batches come every few seconds on a ride: their own, higher limit.
    Route::middleware(['auth:driver_api', 'ensure-phone-verified:driver_api', 'throttle:120,1'])
        ->post('locations/batch', [PresenceController::class, 'locations']);
});
