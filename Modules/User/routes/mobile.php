<?php

use App\Http\Controllers\General\MobileRatingController;
use App\Http\Controllers\General\MobileReferralController;
use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\Mobile\AddressController;
use Modules\User\Http\Controllers\Mobile\FaqController;
use Modules\User\Http\Controllers\Mobile\LegalPageController;
use Modules\User\Http\Controllers\Mobile\MobileAppearanceController;
use Modules\User\Http\Controllers\Mobile\MobileAuthController;
use Modules\User\Http\Controllers\Mobile\MobileProfileController;
use Modules\User\Http\Controllers\Mobile\SupportTicketController;
use Modules\User\Http\Controllers\PhoneChangeController;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::get('faqs', [FaqController::class, 'index']);
    Route::get('legal-pages', [LegalPageController::class, 'show']);

    Route::middleware('guest:user_api')->group(function () {
        Route::post('auth/otp', [MobileAuthController::class, 'requestOtp']);
        Route::post('auth/otp/restore', [MobileAuthController::class, 'requestRestoreOtp']);
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
        Route::delete('profile/avatar', [MobileProfileController::class, 'deleteAvatar']);
        Route::post('profile/email/request', [MobileProfileController::class, 'requestEmailChange']);
        Route::post('profile/email/confirm', [MobileProfileController::class, 'confirmEmailChange']);
        Route::delete('profile/account', [MobileProfileController::class, 'deleteAccount']);

        Route::get('support-tickets', [SupportTicketController::class, 'index']);
        Route::post('support-tickets', [SupportTicketController::class, 'store']);
        Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket');
        Route::patch('support-tickets/{ticket}/status', [SupportTicketController::class, 'status'])->whereNumber('ticket');
        Route::get('support-tickets/{ticket}/messages', [SupportTicketController::class, 'messages'])->whereNumber('ticket');
        Route::post('support-tickets/{ticket}/messages', [SupportTicketController::class, 'sendMessage'])->whereNumber('ticket')->middleware('throttle:30,1');

        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::get('addresses/{id}', [AddressController::class, 'show']);
        Route::match(['put', 'patch'], 'addresses/{id}', [AddressController::class, 'update']);
        Route::delete('addresses/{id}', [AddressController::class, 'destroy']);
        Route::patch('addresses/{id}/set-default', [AddressController::class, 'setDefault']);

        Route::get('ratings/mine', [MobileRatingController::class, 'mine']);
        Route::post('ratings', [MobileRatingController::class, 'store'])->middleware('throttle:10,1');

        Route::get('referrals/my-code', [MobileReferralController::class, 'myCode']);
        Route::post('referrals/track', [MobileReferralController::class, 'track'])->middleware('throttle:10,1');

        Route::get('appearance', [MobileAppearanceController::class, 'show']);
        Route::put('appearance', [MobileAppearanceController::class, 'update']);
    });
});
