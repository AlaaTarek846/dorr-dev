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
        Route::get('rides/{ride}/dispatch-check', [\Modules\Rides\Http\Controllers\DispatchCheckController::class, 'ride'])->whereNumber('ride');
        Route::post('rides/{ride}/wasl-retry', [RideAdminController::class, 'waslRetry'])->whereNumber('ride')->middleware('throttle:30,1');

        // Safety: every SOS, open first (R-110, D-141).
        Route::get('ride-safety', [RideSafetyIncidentController::class, 'index']);
        Route::post('ride-safety/{incident}/acknowledge', [RideSafetyIncidentController::class, 'acknowledge'])->whereNumber('incident');
        Route::post('ride-safety/{incident}/resolve', [RideSafetyIncidentController::class, 'resolve'])->whereNumber('incident');

        Route::get('wasl-logs', [WaslLogController::class, 'index']);
        Route::get('wasl-logs/{waslLog}', [WaslLogController::class, 'show'])->whereNumber('waslLog');

        // Block 06: service quality (AI-19, with DORR AI's reading on request) and rides with fraud signs (AI-06).
        Route::get('rides-quality', [\Modules\Rides\Http\Controllers\Admin\RideInsightsController::class, 'quality']);
        Route::post('rides-quality/explain', [\Modules\Rides\Http\Controllers\Admin\RideInsightsController::class, 'explain'])->middleware('throttle:10,1');
        Route::get('rides-risk', [\Modules\Rides\Http\Controllers\Admin\RideInsightsController::class, 'risky']);
        // O-07, O-08: the rides page's maps — the heat map of requests and the captains on the map.
        Route::get('rides-map/heat', [\Modules\Rides\Http\Controllers\Admin\RideAdminController::class, 'heatMap']);
        Route::get('rides-map/drivers', [\Modules\Rides\Http\Controllers\Admin\RideAdminController::class, 'driversMap']);
        // D-224, D-299: captain payouts — international requests, frozen accounts.
        Route::get('driver-payouts', [\Modules\Rides\Http\Controllers\Admin\DriverPayoutAdminController::class, 'index']);
        Route::post('driver-payouts/{driver}/international', [\Modules\Rides\Http\Controllers\Admin\DriverPayoutAdminController::class, 'international'])->whereNumber('driver');
        Route::post('driver-payouts/{driver}/freeze', [\Modules\Rides\Http\Controllers\Admin\DriverPayoutAdminController::class, 'freeze'])->whereNumber('driver');
        Route::post('driver-payouts/{driver}/unfreeze', [\Modules\Rides\Http\Controllers\Admin\DriverPayoutAdminController::class, 'unfreeze'])->whereNumber('driver');
        // D-239..D-259: captain ranks — the ranks, one captain's record, appeals.
        Route::get('driver-ranks', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'index']);
        Route::post('driver-ranks', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'store']);
        Route::put('driver-ranks/{id}', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'update'])->whereNumber('id');
        Route::delete('driver-ranks/{id}', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'destroy'])->whereNumber('id');
        Route::get('driver-ranks/driver', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'driver']);
        Route::post('driver-ranks/drivers/{driver}/notes', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'note'])->whereNumber('driver');
        Route::get('driver-ranks/appeals', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'appeals']);
        Route::post('driver-ranks/appeals/{note}', [\Modules\Rides\Http\Controllers\Admin\DriverRankAdminController::class, 'answer'])->whereNumber('note');
        // R-146..R-155: DORR points — tiers, badges, challenges, rewards, one rider's points.
        Route::get('ride-loyalty/options', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'options']);
        Route::get('ride-loyalty/member', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'member']);
        Route::post('ride-loyalty/members/{user}/adjust', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'adjust'])->whereNumber('user');
        Route::get('ride-loyalty/{list}', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'index'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards']);
        Route::post('ride-loyalty/{list}', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'store'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards']);
        Route::put('ride-loyalty/{list}/{id}', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'update'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards'])->whereNumber('id');
        Route::delete('ride-loyalty/{list}/{id}', [\Modules\Rides\Http\Controllers\Admin\LoyaltyAdminController::class, 'destroy'])->whereIn('list', ['tiers', 'badges', 'challenges', 'rewards'])->whereNumber('id');
        Route::get('ride-lists/{list}', [RideListController::class, 'index'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::post('ride-lists/{list}', [RideListController::class, 'store'])->whereIn('list', ['cancel-reasons', 'rating-tags']);
        Route::put('ride-lists/{list}/{id}', [RideListController::class, 'update'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
        Route::delete('ride-lists/{list}/{id}', [RideListController::class, 'destroy'])->whereIn('list', ['cancel-reasons', 'rating-tags'])->whereNumber('id');
    });
});
