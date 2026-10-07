<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Admin\ChatReportController;
use Modules\Chat\Http\Controllers\Admin\ChatReportTypeController;
use Modules\Chat\Http\Controllers\Admin\ChatSettingController;
use Modules\Chat\Http\Controllers\Admin\ChatStickerController;
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

        // Sticker packs (POST for update: multipart cover).
        Route::get('chat-sticker-packs', [ChatStickerController::class, 'index']);
        Route::post('chat-sticker-packs', [ChatStickerController::class, 'store']);
        Route::get('chat-sticker-packs/{chat_sticker_pack}', [ChatStickerController::class, 'show']);
        Route::post('chat-sticker-packs/{chat_sticker_pack}', [ChatStickerController::class, 'update']);
        Route::patch('chat-sticker-packs/{chat_sticker_pack}/status', [ChatStickerController::class, 'status']);
        Route::delete('chat-sticker-packs/{chat_sticker_pack}', [ChatStickerController::class, 'destroy']);
        Route::post('chat-sticker-packs/{chat_sticker_pack}/stickers', [ChatStickerController::class, 'addStickers']);
        Route::patch('chat-stickers/{chat_sticker}', [ChatStickerController::class, 'updateSticker']);
        Route::delete('chat-stickers/{chat_sticker}', [ChatStickerController::class, 'destroySticker']);

        Route::get('chat-reports', [ChatReportController::class, 'index']);
        Route::get('chat-reports/{chat_report}', [ChatReportController::class, 'show']);
        Route::put('chat-reports/{chat_report}', [ChatReportController::class, 'update']);
    });
});
