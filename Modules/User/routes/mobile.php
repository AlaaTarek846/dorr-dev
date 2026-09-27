<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\MobileAuthController;
use Modules\User\Http\Controllers\PhoneChangeController;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware('guest:user_api')->group(function () {
        Route::post('auth/otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [MobileAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [MobileAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api','throttle:60,1'])->group(function () {
        Route::get('auth/me', [MobileAuthController::class, 'me']);
        Route::post('auth/logout', [MobileAuthController::class, 'logout']);

        // A code goes to the *new* number; behind the wallet PIN when one already exists.
        Route::post('phone/change', [PhoneChangeController::class, 'start'])->middleware('throttle:5,1,phone-change');
        Route::post('phone/change/confirm', [PhoneChangeController::class, 'confirm'])->middleware('throttle:10,1,phone-change-confirm');
    });
});
