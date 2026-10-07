<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Admin\ChatCategoryController;
use Modules\Chat\Http\Controllers\Admin\ChatDirectoryController;
use Modules\Chat\Http\Controllers\Admin\ChatDorrStoryController;
use Modules\Chat\Http\Controllers\Admin\ChatMomentController;
use Modules\Chat\Http\Controllers\Admin\ChatPackageController;
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

        // Categories for channels and merchant portals (POST for update: multipart icon).
        Route::get('chat-categories/dropdown', [ChatCategoryController::class, 'dropdown']);
        Route::get('chat-categories', [ChatCategoryController::class, 'index']);
        Route::post('chat-categories', [ChatCategoryController::class, 'store']);
        Route::get('chat-categories/{chat_category}', [ChatCategoryController::class, 'show']);
        Route::post('chat-categories/{chat_category}', [ChatCategoryController::class, 'update']);
        Route::patch('chat-categories/{chat_category}/status', [ChatCategoryController::class, 'status']);
        Route::delete('chat-categories/{chat_category}', [ChatCategoryController::class, 'destroy']);

        // Packages: a portal's listing / a channel's verification, priced per country.
        Route::patch('chat-packages/{chat_package}/status', [ChatPackageController::class, 'status']);
        Route::apiResource('chat-packages', ChatPackageController::class);

        // The occasions catalog (DORR Moments) and its date corrections (POST for update: multipart card picture).
        Route::get('chat-moments', [ChatMomentController::class, 'index']);
        Route::post('chat-moments', [ChatMomentController::class, 'store']);
        Route::get('chat-moments/{chat_moment}', [ChatMomentController::class, 'show']);
        Route::post('chat-moments/{chat_moment}', [ChatMomentController::class, 'update']);
        Route::patch('chat-moments/{chat_moment}/status', [ChatMomentController::class, 'status']);
        Route::delete('chat-moments/{chat_moment}', [ChatMomentController::class, 'destroy']);
        Route::get('chat-moments/{chat_moment}/dates', [ChatMomentController::class, 'dates']);
        Route::put('chat-moments/{chat_moment}/dates', [ChatMomentController::class, 'setDate']);

        // Dorr's own stories on the home page (POST for update: multipart photo / video).
        Route::get('chat-dorr-stories', [ChatDorrStoryController::class, 'index']);
        Route::post('chat-dorr-stories', [ChatDorrStoryController::class, 'store']);
        Route::get('chat-dorr-stories/{chat_dorr_story}', [ChatDorrStoryController::class, 'show']);
        Route::post('chat-dorr-stories/{chat_dorr_story}', [ChatDorrStoryController::class, 'update']);
        Route::patch('chat-dorr-stories/{chat_dorr_story}/status', [ChatDorrStoryController::class, 'status']);
        Route::delete('chat-dorr-stories/{chat_dorr_story}', [ChatDorrStoryController::class, 'destroy']);

        // Merchant portals and channels (verification by hand).
        Route::get('chat-portals', [ChatDirectoryController::class, 'portals']);
        Route::patch('chat-portals/{portal}/status', [ChatDirectoryController::class, 'portalStatus']);
        Route::get('chat-channels', [ChatDirectoryController::class, 'channels']);
        Route::patch('chat-channels/{channel}/verify', [ChatDirectoryController::class, 'verify']);

        Route::get('chat-reports', [ChatReportController::class, 'index']);
        Route::get('chat-reports/{chat_report}', [ChatReportController::class, 'show']);
        Route::put('chat-reports/{chat_report}', [ChatReportController::class, 'update']);
    });
});
