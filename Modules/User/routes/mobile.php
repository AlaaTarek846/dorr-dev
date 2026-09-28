<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\Mobile\AddressController;
use Modules\User\Http\Controllers\Mobile\MobileAppearanceController;
use Modules\User\Http\Controllers\Mobile\MobileAuthController;
use Modules\User\Http\Controllers\Mobile\MobileProfileController;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware('guest:user_api')->group(function () {
        Route::post('auth/otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [MobileAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [MobileAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1'])->group(function () {
        Route::get('auth/me', [MobileAuthController::class, 'me']);
        Route::post('auth/logout', [MobileAuthController::class, 'logout']);

        Route::post('profile/phone/request', [MobileProfileController::class, 'requestPhoneChange']);
        Route::post('profile/phone/confirm', [MobileProfileController::class, 'confirmPhoneChange']);
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
