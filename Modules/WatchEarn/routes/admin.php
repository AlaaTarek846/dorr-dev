<?php

use Illuminate\Support\Facades\Route;
use Modules\WatchEarn\Http\Controllers\Admin\WatchEarnAdController;
use Modules\WatchEarn\Http\Controllers\Admin\WatchEarnCommentReportController;
use Modules\WatchEarn\Http\Controllers\Admin\WatchEarnSettingController;

Route::middleware('locale')->prefix('admin/v1/watch-earn')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('settings', [WatchEarnSettingController::class, 'index']);
        Route::put('settings/{country}', [WatchEarnSettingController::class, 'update']);

        Route::get('ads', [WatchEarnAdController::class, 'index']);
        Route::get('ads/counts', [WatchEarnAdController::class, 'counts']);
        Route::get('finance', [WatchEarnAdController::class, 'finance']);
        Route::get('finance/export', [WatchEarnAdController::class, 'financeExport']);
        Route::get('ads/{ad}', [WatchEarnAdController::class, 'show']);
        Route::get('ads/{ad}/reports', [WatchEarnAdController::class, 'reports']);
        Route::post('ads/{ad}/approve', [WatchEarnAdController::class, 'approve']);
        Route::post('ads/{ad}/reject', [WatchEarnAdController::class, 'reject']);
        Route::post('ads/{ad}/suspend', [WatchEarnAdController::class, 'suspend']);
        Route::post('ads/{ad}/unsuspend', [WatchEarnAdController::class, 'unsuspend']);

        Route::get('comment-reports', [WatchEarnCommentReportController::class, 'index']);
        Route::post('comment-reports/{report}/decide', [WatchEarnCommentReportController::class, 'decide'])->whereNumber('report');
    });
});
