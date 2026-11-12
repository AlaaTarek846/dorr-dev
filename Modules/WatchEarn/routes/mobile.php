<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;
use Modules\WatchEarn\Http\Controllers\AdvertiserController;
use Modules\WatchEarn\Http\Controllers\AdWalletController;
use Modules\WatchEarn\Http\Controllers\WatchController;

// Watch & Earn in the mobile app (docs/watch_earn_tasks.md).
Route::middleware('locale')->prefix('mobile/v1/watch-earn')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        // The viewer.
        Route::get('feed', [WatchController::class, 'feed']);
        Route::get('ads/{ad}', [WatchController::class, 'show']);
        Route::post('ads/{ad}/start', [WatchController::class, 'start'])->middleware('throttle:60,1');
        Route::post('views/{view}/complete', [WatchController::class, 'complete'])->middleware('throttle:60,1');
        Route::get('ads/{ad}/comments', [WatchController::class, 'comments']);
        Route::post('ads/{ad}/comments', [WatchController::class, 'comment'])->middleware('throttle:20,1');
        Route::delete('ads/{ad}/comments/{comment}', [WatchController::class, 'deleteComment'])->whereNumber('comment');
        Route::post('ads/{ad}/reaction', [WatchController::class, 'react'])->middleware('throttle:30,1');
        Route::delete('ads/{ad}/reaction', [WatchController::class, 'unreact']);
        Route::post('ads/{ad}/report', [WatchController::class, 'report'])->middleware('throttle:10,1');
        Route::get('rewards', [WatchController::class, 'rewards']);

        // The advertiser.
        Route::get('advertiser/options', [AdvertiserController::class, 'options']);
        Route::post('advertiser/audience', [AdvertiserController::class, 'audience'])->middleware('throttle:120,1');
        Route::get('advertiser/ads', [AdvertiserController::class, 'index']);
        Route::post('advertiser/ads', [AdvertiserController::class, 'store'])->middleware('throttle:10,1');
        Route::get('advertiser/ads/{ad}', [AdvertiserController::class, 'show']);
        Route::get('advertiser/ads/{ad}/stats', [AdvertiserController::class, 'stats']);
        Route::get('advertiser/ads/{ad}/export', [AdvertiserController::class, 'export'])->middleware('throttle:10,1');
        // POST for update: multipart (the file).
        Route::post('advertiser/ads/{ad}', [AdvertiserController::class, 'update'])->middleware('throttle:10,1');
        Route::post('advertiser/ads/{ad}/budget', [AdvertiserController::class, 'budget']);
        Route::post('advertiser/ads/{ad}/extend', [AdvertiserController::class, 'extend']);
        Route::post('advertiser/ads/{ad}/pause', [AdvertiserController::class, 'pause']);
        Route::post('advertiser/ads/{ad}/resume', [AdvertiserController::class, 'resume']);
        Route::post('advertiser/ads/{ad}/stop', [AdvertiserController::class, 'stop']);
        Route::post('advertiser/ads/{ad}/comments/{comment}/report', [AdvertiserController::class, 'reportComment'])->whereNumber('comment')->middleware('throttle:20,1');

        // The ad wallet.
        Route::get('ad-wallet', [AdWalletController::class, 'show']);
        Route::post('ad-wallet/fund/preview', [AdWalletController::class, 'fundPreview']);
        Route::post('ad-wallet/fund', [AdWalletController::class, 'fund'])->middleware(['throttle:20,1', RequiresWalletPin::class]);
        Route::post('ad-wallet/return/preview', [AdWalletController::class, 'returnPreview']);
        Route::post('ad-wallet/return', [AdWalletController::class, 'return'])->middleware('throttle:20,1');
    });
});
