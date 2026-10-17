<?php

use Illuminate\Support\Facades\Route;
use Modules\Rides\Http\Controllers\Admin\DriverRegulatorAdminController;
use Modules\Rides\Http\Controllers\Admin\RideAdminController;
use Modules\Rides\Http\Controllers\Admin\RideCaseController;
use Modules\Rides\Http\Controllers\Admin\RideCategoryController;
use Modules\Rides\Http\Controllers\Admin\RideFeatureRuleController;
use Modules\Rides\Http\Controllers\Admin\RideListController;
use Modules\Rides\Http\Controllers\Admin\RidePolicyController;
use Modules\Rides\Http\Controllers\Admin\RideSafetyIncidentController;
use Modules\Rides\Http\Controllers\Admin\RideSettingController;
use Modules\Rides\Http\Controllers\Admin\RideZoneController;
use Modules\Rides\Http\Controllers\Admin\WaslLogController;

// Admin → Rides (docs/rides-plan.md §4.4): settings, switches, categories, prices & rules, rides, Wasl, lists.
Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('ride-settings', [RideSettingController::class, 'show']);
        Route::put('ride-settings', [RideSettingController::class, 'update']);

        // A driver's standing at Wasl, and the dashboard's "register" / "check again".
        Route::get('drivers/{driver}/regulator', [DriverRegulatorAdminController::class, 'show'])->whereNumber('driver');
        Route::post('drivers/{driver}/regulator/register', [DriverRegulatorAdminController::class, 'register'])->whereNumber('driver')->middleware('throttle:20,1');
        Route::post('drivers/{driver}/regulator/check', [DriverRegulatorAdminController::class, 'check'])->whereNumber('driver')->middleware('throttle:20,1');

        Route::get('ride-feature-rules/options', [RideFeatureRuleController::class, 'options']);
        Route::apiResource('ride-feature-rules', RideFeatureRuleController::class)->except(['show']);

        Route::get('ride-categories', [RideCategoryController::class, 'index']);
        Route::put('ride-categories/{rideCategory}', [RideCategoryController::class, 'update'])->whereNumber('rideCategory');

        Route::get('ride-policies/options', [RidePolicyController::class, 'options']);
        Route::get('ride-policies/scopes', [RidePolicyController::class, 'index']);
        Route::get('ride-policies', [RidePolicyController::class, 'show']);
        Route::put('ride-policies', [RidePolicyController::class, 'update']);

        // Zones (airport, pickup-only, blocked…) and their approved pickup points (R-16..R-18).
        Route::get('ride-zones/options', [RideZoneController::class, 'options']);
        Route::get('ride-zones', [RideZoneController::class, 'index']);
        Route::post('ride-zones', [RideZoneController::class, 'store']);
        Route::put('ride-zones/{zone}', [RideZoneController::class, 'update'])->whereNumber('zone');
        Route::delete('ride-zones/{zone}', [RideZoneController::class, 'destroy'])->whereNumber('zone');
        Route::post('ride-zones/{zone}/points', [RideZoneController::class, 'storePoint'])->whereNumber('zone');
        Route::put('ride-zones/{zone}/points/{point}', [RideZoneController::class, 'updatePoint'])->whereNumber(['zone', 'point']);
        Route::delete('ride-zones/{zone}/points/{point}', [RideZoneController::class, 'destroyPoint'])->whereNumber(['zone', 'point']);

        // Cases: complaints, disputes, unpaid-ride reports (R-133..R-140, D-229..D-238).
        Route::get('ride-cases/options', [RideCaseController::class, 'options']);
        Route::get('ride-cases', [RideCaseController::class, 'index']);
        Route::get('ride-cases/{case}', [RideCaseController::class, 'show'])->whereNumber('case');
        Route::post('ride-cases/{case}/status', [RideCaseController::class, 'status'])->whereNumber('case');
        Route::post('ride-cases/{case}/messages', [RideCaseController::class, 'message'])->whereNumber('case');
        Route::post('ride-cases/{case}/files', [RideCaseController::class, 'file'])->whereNumber('case');
        Route::post('ride-cases/{case}/decide', [RideCaseController::class, 'decide'])->whereNumber('case');

        Route::get('rides', [RideAdminController::class, 'index']);
        Route::get('rides/{ride}', [RideAdminController::class, 'show'])->whereNumber('ride');
        Route::post('rides/{ride}/wasl-retry', [RideAdminController::class, 'waslRetry'])->whereNumber('ride')->middleware('throttle:30,1');

        // Safety: every SOS, open first (R-110, D-141).
        Route::get('ride-safety', [RideSafetyIncidentController::class, 'index']);
        Route::post('ride-safety/{incident}/acknowledge', [RideSafetyIncidentController::class, 'acknowledge'])->whereNumber('incident');
        Route::post('ride-safety/{incident}/resolve', [RideSafetyIncidentController::class, 'resolve'])->whereNumber('incident');

        Route::get('wasl-logs', [WaslLogController::class, 'index']);
        Route::get('wasl-logs/{waslLog}', [WaslLogController::class, 'show'])->whereNumber('waslLog');

        Route::get('ride-lists/{list}', [RideListController::class, 'index'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::post('ride-lists/{list}', [RideListController::class, 'store'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::put('ride-lists/{list}/{id}', [RideListController::class, 'update'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
        Route::delete('ride-lists/{list}/{id}', [RideListController::class, 'destroy'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
    });
});
