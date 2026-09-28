<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Admin\ChatSettingController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('chat-settings', [ChatSettingController::class, 'show']);
        Route::put('chat-settings', [ChatSettingController::class, 'update']);
    });
});
