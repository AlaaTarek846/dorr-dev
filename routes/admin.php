<?php

use App\Http\Controllers\General\CountryController;
use App\Http\Controllers\General\CurrencyController;
use App\Http\Controllers\General\DashboardThemeController;
use App\Http\Controllers\General\FaqController;
use App\Http\Controllers\General\FlagController;
use App\Http\Controllers\General\LanguageController;
use App\Http\Controllers\General\MobileAppColorDefaultController;
use App\Http\Controllers\General\MobileAppFontController;
use App\Http\Controllers\General\PlatformSettingController;
use App\Http\Controllers\General\PrivacyPolicyController;
use App\Http\Controllers\General\ServiceCategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| General catalog routes (Admin API)
|--------------------------------------------------------------------------
|
| Shared catalog under App\Http\Controllers\General\.
| Loaded from Modules/Admin/routes/admin.php inside prefix admin/v1.
|
*/

Route::middleware('auth:admin_api')->group(function () {
    Route::get('platform-settings', [PlatformSettingController::class, 'show']);
    Route::post('platform-settings', [PlatformSettingController::class, 'update']);

    Route::get('mobile-app-color-defaults', [MobileAppColorDefaultController::class, 'show']);
    Route::put('mobile-app-color-defaults', [MobileAppColorDefaultController::class, 'update']);

    Route::get('mobile-app-fonts/dropdown', [MobileAppFontController::class, 'dropdown']);
    Route::post('mobile-app-fonts/delete-multiple', [MobileAppFontController::class, 'deleteMultiple']);
    Route::post('mobile-app-fonts/{mobile_app_font}/restore', [MobileAppFontController::class, 'restore']);
    Route::delete('mobile-app-fonts/{mobile_app_font}/force', [MobileAppFontController::class, 'forceDestroy']);
    Route::patch('mobile-app-fonts/{mobile_app_font}/status', [MobileAppFontController::class, 'changeStatus']);
    Route::apiResource('mobile-app-fonts', MobileAppFontController::class);

    Route::get('service-categories/tree', [ServiceCategoryController::class, 'tree']);
    Route::get('service-categories/tree-options', [ServiceCategoryController::class, 'treeOptions']);
    Route::get('service-categories/leaf-options', [ServiceCategoryController::class, 'leafOptions']);
    Route::put('service-categories/reorder', [ServiceCategoryController::class, 'reorder']);

    Route::get('faqs/ordered', [FaqController::class, 'ordered']);
    Route::put('faqs/reorder', [FaqController::class, 'reorder']);

    Route::post('dashboard-themes/delete-multiple', [DashboardThemeController::class, 'deleteMultiple']);
    Route::post('dashboard-themes/{dashboard_theme}/restore', [DashboardThemeController::class, 'restore']);
    Route::delete('dashboard-themes/{dashboard_theme}/force', [DashboardThemeController::class, 'forceDestroy']);
    Route::patch('dashboard-themes/{dashboard_theme}/status', [DashboardThemeController::class, 'changeStatus']);
    Route::apiResource('dashboard-themes', DashboardThemeController::class);

    foreach ([
        ['flags', FlagController::class, 'flag'],
        ['languages', LanguageController::class, 'language'],
        ['currencies', CurrencyController::class, 'currency'],
        ['countries', CountryController::class, 'country'],
        ['service-categories', ServiceCategoryController::class, 'service_category'],
        ['faqs', FaqController::class, 'faq'],
        ['privacy-policies', PrivacyPolicyController::class, 'privacy_policy'],
    ] as [$uri, $controller, $parameter]) {
        if ($uri !== 'languages') {
            Route::get("{$uri}/dropdown", [$controller, 'dropdown']);
        }

        if ($uri === 'currencies') {
            Route::post("{$uri}/sync-exchange-rates", [$controller, 'syncExchangeRates']);
        }

        Route::post("{$uri}/delete-multiple", [$controller, 'deleteMultiple']);
        Route::post("{$uri}/{{$parameter}}/restore", [$controller, 'restore']);
        Route::delete("{$uri}/{{$parameter}}/force", [$controller, 'forceDestroy']);
        Route::patch("{$uri}/{{$parameter}}/status", [$controller, 'changeStatus']);
        Route::apiResource($uri, $controller);
    }
});
