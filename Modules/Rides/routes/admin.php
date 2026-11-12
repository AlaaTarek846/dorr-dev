<?php

use App\Support\Admin\AdminPermissionMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Rides\Http\Controllers\Admin\DriverPayoutAdminController;
use Modules\Rides\Http\Controllers\Admin\DriverRankAdminController;
use Modules\Rides\Http\Controllers\Admin\DriverRegulatorAdminController;
use Modules\Rides\Http\Controllers\Admin\DriverServiceAdminController;
use Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController;
use Modules\Rides\Http\Controllers\Admin\RideAdminController;
use Modules\Rides\Http\Controllers\Admin\RideCaseController;
use Modules\Rides\Http\Controllers\Admin\RideCategoryController;
use Modules\Rides\Http\Controllers\Admin\RideFeatureRuleController;
use Modules\Rides\Http\Controllers\Admin\RideInsightsController;
use Modules\Rides\Http\Controllers\Admin\RideListController;
use Modules\Rides\Http\Controllers\Admin\RidePolicyController;
use Modules\Rides\Http\Controllers\Admin\RideSafetyIncidentController;
use Modules\Rides\Http\Controllers\Admin\RideSettingController;
use Modules\Rides\Http\Controllers\Admin\RideZoneController;
use Modules\Rides\Http\Controllers\Admin\WaslLogController;
use Modules\Rides\Http\Controllers\DispatchCheckController;

// Admin → Rides (docs/rides-plan.md §4.4): settings, switches, categories, prices & rules, rides, Wasl, lists.
Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('ride-settings', [RideSettingController::class, 'show']);
        Route::put('ride-settings', [RideSettingController::class, 'update']);
        Route::post('ride-settings/check-google', [RideSettingController::class, 'checkGoogle']);

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
        // AI-12: DORR AI's reading of the case, again on request.
        Route::post('ride-cases/{case}/ai-triage', [RideCaseController::class, 'triage'])->whereNumber('case')->middleware('throttle:20,1');

        Route::get('rides', [RideAdminController::class, 'index']);
        Route::get('rides/{ride}', [RideAdminController::class, 'show'])->whereNumber('ride');
        // O-47: why this request did or didn't reach each captain around it.
        Route::get('rides/{ride}/dispatch-check', [DispatchCheckController::class, 'ride'])->whereNumber('ride')
            ->middleware(AdminPermissionMiddleware::permission('rides', 'view'));
        Route::post('rides/{ride}/wasl-retry', [RideAdminController::class, 'waslRetry'])->whereNumber('ride')->middleware('throttle:30,1');

        // Safety: every SOS, open first (R-110, D-141).
        Route::get('ride-safety', [RideSafetyIncidentController::class, 'index']);
        Route::post('ride-safety/{incident}/acknowledge', [RideSafetyIncidentController::class, 'acknowledge'])->whereNumber('incident');
        Route::post('ride-safety/{incident}/resolve', [RideSafetyIncidentController::class, 'resolve'])->whereNumber('incident');

        Route::get('wasl-logs', [WaslLogController::class, 'index']);
        Route::get('wasl-logs/{waslLog}', [WaslLogController::class, 'show'])->whereNumber('waslLog');

        // Block 06: service quality (AI-19, with DORR AI's reading on request) and rides with fraud signs (AI-06).
        Route::get('rides-quality', [RideInsightsController::class, 'quality']);
        Route::post('rides-quality/explain', [RideInsightsController::class, 'explain'])->middleware('throttle:10,1');
        Route::get('rides-risk', [RideInsightsController::class, 'risky']);
        // O-07, O-08: the rides page's maps — the heat map of requests and the captains on the map.
        Route::get('rides-map/heat', [RideAdminController::class, 'heatMap']);
        Route::get('rides-map/drivers', [RideAdminController::class, 'driversMap']);
        // D-224, D-299: captain payouts — international requests, frozen accounts.
        Route::get('driver-payouts', [DriverPayoutAdminController::class, 'index']);
        Route::post('driver-payouts/{driver}/international', [DriverPayoutAdminController::class, 'international'])->whereNumber('driver');
        Route::post('driver-payouts/{driver}/freeze', [DriverPayoutAdminController::class, 'freeze'])->whereNumber('driver');
        Route::post('driver-payouts/{driver}/unfreeze', [DriverPayoutAdminController::class, 'unfreeze'])->whereNumber('driver');
        // D-239..D-259: captain ranks — the ranks, one captain's record, appeals.
        // O-89: captain services, and per country which kind of vehicle works in which.
        Route::get('driver-services', [DriverServiceAdminController::class, 'index']);
        Route::post('driver-services', [DriverServiceAdminController::class, 'store']);
        Route::put('driver-services/{id}', [DriverServiceAdminController::class, 'update'])->whereNumber('id');
        Route::delete('driver-services/{id}', [DriverServiceAdminController::class, 'destroy'])->whereNumber('id');
        Route::get('driver-service-rules', [DriverServiceAdminController::class, 'rules']);
        Route::put('driver-service-rules', [DriverServiceAdminController::class, 'saveRules']);
        Route::get('driver-ranks', [DriverRankAdminController::class, 'index']);
        Route::post('driver-ranks', [DriverRankAdminController::class, 'store']);
        Route::put('driver-ranks/{id}', [DriverRankAdminController::class, 'update'])->whereNumber('id');
        Route::delete('driver-ranks/{id}', [DriverRankAdminController::class, 'destroy'])->whereNumber('id');
        Route::get('driver-ranks/driver', [DriverRankAdminController::class, 'driver']);
        Route::post('driver-ranks/drivers/{driver}/notes', [DriverRankAdminController::class, 'note'])->whereNumber('driver');
        Route::get('driver-ranks/appeals', [DriverRankAdminController::class, 'appeals']);
        Route::post('driver-ranks/appeals/{note}', [DriverRankAdminController::class, 'answer'])->whereNumber('note');
        // R-146..R-155: DORR points — tiers, badges, challenges, rewards, one rider's points.
        Route::get('ride-loyalty/options', [LoyaltyAdminController::class, 'options']);
        Route::get('ride-loyalty/member', [LoyaltyAdminController::class, 'member']);
        Route::post('ride-loyalty/members/{user}/adjust', [LoyaltyAdminController::class, 'adjust'])->whereNumber('user');
        Route::get('ride-loyalty/{list}', [LoyaltyAdminController::class, 'index'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards']);
        Route::post('ride-loyalty/{list}', [LoyaltyAdminController::class, 'store'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards']);
        Route::put('ride-loyalty/{list}/{id}', [LoyaltyAdminController::class, 'update'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards'])->whereNumber('id');
        Route::delete('ride-loyalty/{list}/{id}', [LoyaltyAdminController::class, 'destroy'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards'])->whereNumber('id');
        Route::get('ride-lists/{list}', [RideListController::class, 'index'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::post('ride-lists/{list}', [RideListController::class, 'store'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::put('ride-lists/{list}/{id}', [RideListController::class, 'update'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
        Route::delete('ride-lists/{list}/{id}', [RideListController::class, 'destroy'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
    });
});
