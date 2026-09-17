<?php

use App\Http\Controllers\General\CountryController;
use App\Http\Controllers\General\CurrencyController;
use App\Http\Controllers\General\DashboardThemeController;
use App\Http\Controllers\General\FlagController;
use App\Http\Controllers\General\LanguageController;
use App\Http\Controllers\General\PlatformSettingController;
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

Route::get('platform-settings/branding', [PlatformSettingController::class, 'branding']);
Route::get('languages/dropdown', [LanguageController::class, 'dropdown']);

Route::middleware('auth:admin_api')->group(function () {
    Route::get('platform-settings', [PlatformSettingController::class, 'show']);
    Route::post('platform-settings', [PlatformSettingController::class, 'update']);

    Route::get('service-categories/tree', [ServiceCategoryController::class, 'tree']);
    Route::get('service-categories/tree-options', [ServiceCategoryController::class, 'treeOptions']);
    Route::get('service-categories/leaf-options', [ServiceCategoryController::class, 'leafOptions']);

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
