<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Admin\ChatReportController;
use Modules\Chat\Http\Controllers\Admin\ChatReportTypeController;
use Modules\Chat\Http\Controllers\Admin\ChatSettingController;
use Modules\Chat\Http\Controllers\Admin\ChatThemeController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('chat-settings', [ChatSettingController::class, 'show']);
        Route::put('chat-settings', [ChatSettingController::class, 'update']);

        // POST for update: multipart (wallpaper upload).
        Route::get('chat-themes', [ChatThemeController::class, 'index']);
        Route::post('chat-themes', [ChatThemeController::class, 'store']);
        Route::get('chat-themes/{chat_theme}', [ChatThemeController::class, 'show']);
        Route::post('chat-themes/{chat_theme}', [ChatThemeController::class, 'update']);
        Route::patch('chat-themes/{chat_theme}/status', [ChatThemeController::class, 'status']);
        Route::delete('chat-themes/{chat_theme}', [ChatThemeController::class, 'destroy']);

        Route::get('chat-report-types/dropdown', [ChatReportTypeController::class, 'dropdown']);
        Route::patch('chat-report-types/{chat_report_type}/status', [ChatReportTypeController::class, 'status']);
        Route::apiResource('chat-report-types', ChatReportTypeController::class);

        Route::get('chat-reports', [ChatReportController::class, 'index']);
        Route::get('chat-reports/{chat_report}', [ChatReportController::class, 'show']);
        Route::put('chat-reports/{chat_report}', [ChatReportController::class, 'update']);
    });
});
