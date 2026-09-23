<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\MobileAuthController;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware('guest:user_api')->group(function () {
        Route::post('auth/otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [MobileAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [MobileAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api','throttle:60,1'])->group(function () {
        Route::get('auth/me', [MobileAuthController::class, 'me']);
        Route::post('auth/logout', [MobileAuthController::class, 'logout']);
    });
});
