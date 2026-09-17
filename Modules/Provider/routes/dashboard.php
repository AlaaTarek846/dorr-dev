<?php

use App\Http\Controllers\General\CountryController;
use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\ProviderAuthController;
use Modules\Provider\Http\Controllers\ProviderPasswordResetController;
use Modules\Provider\Http\Controllers\ProviderProfileController;
use Modules\Provider\Http\Controllers\ProviderRegistrationController;

Route::middleware('locale')->prefix('provider/v1')->group(function () {
    Route::middleware('guest:provider_api')->group(function () {
        Route::post('login', [ProviderAuthController::class, 'login']);
        Route::post('check-token', [ProviderAuthController::class, 'checkToken']);
        Route::post('register', [ProviderRegistrationController::class, 'register']);
        Route::post('verify-email', [ProviderRegistrationController::class, 'verifyEmail']);
        Route::post('resend-verification', [ProviderRegistrationController::class, 'resendVerification']);
        Route::post('create-password', [ProviderRegistrationController::class, 'createPassword']);
        Route::post('forgot-password', [ProviderPasswordResetController::class, 'sendResetLink']);
        Route::post('reset-password', [ProviderPasswordResetController::class, 'resetPassword']);
    });

    Route::middleware('auth:provider_api')->group(function () {
        Route::get('countries/dropdown', [CountryController::class, 'dropdown']);
        Route::get('me', [ProviderAuthController::class, 'me']);
        Route::post('logout', [ProviderAuthController::class, 'logout']);
        Route::post('profile', [ProviderProfileController::class, 'update']);
        Route::put('profile/password', [ProviderProfileController::class, 'updatePassword']);
    });
});
