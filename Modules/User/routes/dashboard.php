<?php

use App\Http\Controllers\General\CountryController;
use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserAuthController;
use Modules\User\Http\Controllers\UserPasswordResetController;
use Modules\User\Http\Controllers\UserProfileController;
use Modules\User\Http\Controllers\UserRegistrationController;

Route::middleware('locale')->prefix('user/v1')->group(function () {
    Route::middleware('guest:user_api')->group(function () {
        Route::post('login', [UserAuthController::class, 'login']);
        Route::post('check-token', [UserAuthController::class, 'checkToken']);
        Route::post('register', [UserRegistrationController::class, 'register']);
        Route::post('verify-email', [UserRegistrationController::class, 'verifyEmail']);
        Route::post('resend-verification', [UserRegistrationController::class, 'resendVerification']);
        Route::post('create-password', [UserRegistrationController::class, 'createPassword']);
        Route::post('forgot-password', [UserPasswordResetController::class, 'sendResetLink']);
        Route::post('reset-password', [UserPasswordResetController::class, 'resetPassword']);
    });

    Route::middleware('auth:user_api')->group(function () {
        Route::get('countries/dropdown', [CountryController::class, 'dropdown']);
        Route::get('me', [UserAuthController::class, 'me']);
        Route::post('logout', [UserAuthController::class, 'logout']);
        Route::post('profile', [UserProfileController::class, 'update']);
        Route::put('profile/password', [UserProfileController::class, 'updatePassword']);
    });
});
