<?php

use Illuminate\Support\Facades\Route;
use Modules\Sports\Http\Controllers\Admin\SportsCompetitionController;
use Modules\Sports\Http\Controllers\Admin\SportsContestController;
use Modules\Sports\Http\Controllers\Admin\SportsSettingController;
use Modules\Sports\Http\Controllers\Admin\SportsUsageController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('sports-settings', [SportsSettingController::class, 'show']);
        Route::put('sports-settings', [SportsSettingController::class, 'update']);

        Route::get('sports-competitions', [SportsCompetitionController::class, 'index']);
        Route::post('sports-competitions/import', [SportsCompetitionController::class, 'import']);
        Route::patch('sports-competitions/bulk', [SportsCompetitionController::class, 'bulk']);
        Route::patch('sports-competitions/{sports_competition}', [SportsCompetitionController::class, 'update']);

        Route::get('sports-contests', [SportsContestController::class, 'index']);
        Route::post('sports-contests', [SportsContestController::class, 'store']);
        Route::get('sports-contests/{contest}', [SportsContestController::class, 'show']);
        Route::patch('sports-contests/{contest}', [SportsContestController::class, 'update']);
        Route::post('sports-contests/{contest}/open', [SportsContestController::class, 'open']);
        Route::post('sports-contests/{contest}/cancel', [SportsContestController::class, 'cancel']);
        Route::post('sports-contests/{contest}/settle', [SportsContestController::class, 'settle']);
        Route::patch('sports-contests/{contest}/winners/{winner}', [SportsContestController::class, 'winner']);

        Route::get('sports-usage', [SportsUsageController::class, 'show']);
        Route::post('sports-usage/sync', [SportsUsageController::class, 'sync']);
    });
});
