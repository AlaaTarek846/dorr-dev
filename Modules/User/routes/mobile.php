<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\Mobile\AddressController;
use Modules\User\Http\Controllers\Mobile\MobileAppearanceController;
use Modules\User\Http\Controllers\Mobile\MobileAuthController;
use Modules\User\Http\Controllers\Mobile\MobileProfileController;
use Modules\User\Http\Controllers\PhoneChangeController;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware('guest:user_api')->group(function () {
        Route::post('auth/otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [MobileAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [MobileAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1'])->group(function () {
        Route::get('auth/me', [MobileAuthController::class, 'me']);
        Route::post('auth/logout', [MobileAuthController::class, 'logout']);

        // A code goes to the *new* number; behind the wallet PIN when one already exists.
        Route::post('phone/change', [PhoneChangeController::class, 'start'])->middleware('throttle:5,1,phone-change');
        Route::post('phone/change/confirm', [PhoneChangeController::class, 'confirm'])->middleware('throttle:10,1,phone-change-confirm');

        // Same guarded flow as phone/change — the profile screen's older paths must not bypass it.
        Route::post('profile/phone/request', [PhoneChangeController::class, 'start'])->middleware('throttle:5,1,phone-change');
        Route::post('profile/phone/confirm', [PhoneChangeController::class, 'confirm'])->middleware('throttle:10,1,phone-change-confirm');
        Route::put('profile/identity', [MobileProfileController::class, 'updateIdentity']);
        Route::post('profile/avatar', [MobileProfileController::class, 'updateAvatar']);
        Route::post('profile/email/request', [MobileProfileController::class, 'requestEmailChange']);
        Route::post('profile/email/confirm', [MobileProfileController::class, 'confirmEmailChange']);

        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::get('addresses/{id}', [AddressController::class, 'show']);
        Route::match(['put', 'patch'], 'addresses/{id}', [AddressController::class, 'update']);
        Route::delete('addresses/{id}', [AddressController::class, 'destroy']);
        Route::patch('addresses/{id}/set-default', [AddressController::class, 'setDefault']);

        Route::get('appearance', [MobileAppearanceController::class, 'show']);
        Route::put('appearance', [MobileAppearanceController::class, 'update']);
    });
});
