<?php

use App\Http\Controllers\General\CountryController;
use App\Http\Controllers\General\CurrencyController;
use App\Http\Controllers\General\FlagController;
use App\Http\Controllers\General\LanguageController;
use App\Http\Controllers\General\PlatformSettingController;
use App\Http\Controllers\General\ServiceCategoryController;
use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminAuthController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AdminProfileController;
use Modules\User\Http\Controllers\UserController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::get('platform-settings/branding', [PlatformSettingController::class, 'branding']);
    Route::get('languages/dropdown', [LanguageController::class, 'dropdown']);

    Route::middleware('guest:admin_api')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login']);
        Route::post('check-token', [AdminAuthController::class, 'checkToken']);
    });

    Route::middleware('auth:admin_api')->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::post('profile', [AdminProfileController::class, 'update']);
        Route::put('profile/password', [AdminProfileController::class, 'updatePassword']);

        Route::get('platform-settings', [PlatformSettingController::class, 'show']);
        Route::post('platform-settings', [PlatformSettingController::class, 'update']);

        Route::post('admins/delete-multiple', [AdminController::class, 'deleteMultiple']);
        Route::patch('admins/{admin}/status', [AdminController::class, 'changeStatus']);
        Route::apiResource('admins', AdminController::class)->names('admin');

        Route::post('users/delete-multiple', [UserController::class, 'deleteMultiple']);
        Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
        Route::apiResource('users', UserController::class);

        Route::get('service-categories/tree', [ServiceCategoryController::class, 'tree']);
        Route::get('service-categories/leaf-options', [ServiceCategoryController::class, 'leafOptions']);

        foreach ([
            ['flags', FlagController::class, 'flag'],
            ['languages', LanguageController::class, 'language'],
            ['currencies', CurrencyController::class, 'currency'],
            ['countries', CountryController::class, 'country'],
            ['service-categories', ServiceCategoryController::class, 'service_category'],
        ] as [$uri, $controller, $parameter]) {
            if ($uri !== 'languages') {
                Route::get("{$uri}/dropdown", [$controller, 'dropdown']);
            }

            if ($uri === 'currencies') {
                Route::post("{$uri}/sync-exchange-rates", [$controller, 'syncExchangeRates']);
            }

            Route::post("{$uri}/delete-multiple", [$controller, 'deleteMultiple']);
            Route::patch("{$uri}/{{$parameter}}/status", [$controller, 'changeStatus']);
            Route::apiResource($uri, $controller);
        }
    });
});
