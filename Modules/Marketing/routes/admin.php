<?php

use Illuminate\Support\Facades\Route;
use Modules\Marketing\Http\Controllers\Admin\FeatureRuleController;
use Modules\Marketing\Http\Controllers\Admin\OverviewController;
use Modules\Marketing\Http\Controllers\Admin\PackageController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1/marketing')->name('marketing.admin.')->group(function () {
    Route::get('feature-rules/options', [FeatureRuleController::class, 'options']);
    Route::get('feature-rules', [FeatureRuleController::class, 'index']);
    Route::post('feature-rules', [FeatureRuleController::class, 'store']);
    Route::post('feature-rules/copy', [FeatureRuleController::class, 'copy']);

    Route::get('packages', [PackageController::class, 'index']);
    Route::post('packages', [PackageController::class, 'store']);
    Route::get('packages/{package}', [PackageController::class, 'show'])->whereNumber('package');
    Route::put('packages/{package}', [PackageController::class, 'update'])->whereNumber('package');
    Route::delete('packages/{package}', [PackageController::class, 'destroy'])->whereNumber('package');

    Route::get('subscriptions', [OverviewController::class, 'subscriptions']);
    Route::get('projects', [OverviewController::class, 'projects']);
    Route::get('jobs', [OverviewController::class, 'jobs']);
    Route::get('audit', [OverviewController::class, 'audit']);
});
