<?php

use Illuminate\Support\Facades\Route;
use Modules\Sports\Http\Controllers\SportsController;
use Modules\Sports\Http\Controllers\SportsLibraryController;
use Modules\Sports\Http\Controllers\SportsMediaController;
use Modules\Sports\Http\Controllers\SportsPlayController;
use Modules\Sports\Support\SportsMedia;

// DORR Sports in the mobile app (spec 183–200).
Route::middleware('locale')->prefix('mobile/v1/sports')->group(function () {
    // Logos and photos, from our copy (no sign-in: images load like any picture).
    Route::get('media/{path}', [SportsMediaController::class, 'show'])->where('path', SportsMedia::PATH)->middleware('throttle:600,1');

    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:240,1', 'country', 'remember-locale'])->group(function () {
        Route::get('home', [SportsController::class, 'home']);
        Route::get('matches', [SportsController::class, 'matches']);
        Route::get('widget', [SportsController::class, 'widget']);
        Route::get('matches/{match}', [SportsController::class, 'match']);
        Route::get('competitions', [SportsController::class, 'competitions']);
        Route::get('competitions/{competition}', [SportsController::class, 'competition'])->whereNumber('competition');
        Route::get('teams', [SportsController::class, 'teams']);
        Route::get('teams/{team}', [SportsController::class, 'team'])->whereNumber('team');
        Route::get('follows', [SportsController::class, 'follows']);
        Route::post('follows', [SportsController::class, 'follow']);
        Route::delete('follows/{follow}', [SportsController::class, 'unfollow'])->whereNumber('follow');
        Route::get('preferences', [SportsController::class, 'preferences']);
        Route::put('preferences', [SportsController::class, 'savePreferences']);

        // The deep pages (docs/sports-plan.md §10): rounds, leaders, squads, players, coaches, insights.
        Route::get('competitions/{competition}/rounds', [SportsLibraryController::class, 'rounds'])->whereNumber('competition');
        Route::get('competitions/{competition}/leaders', [SportsLibraryController::class, 'leaders'])->whereNumber('competition');
        Route::get('teams/{team}/squad', [SportsLibraryController::class, 'squad'])->whereNumber('team');
        Route::get('teams/{team}/statistics', [SportsLibraryController::class, 'teamStatistics'])->whereNumber('team');
        Route::get('teams/{team}/transfers', [SportsLibraryController::class, 'transfers'])->whereNumber('team');
        Route::get('players/{player}', [SportsLibraryController::class, 'player'])->whereNumber('player');
        Route::get('players/{player}/career', [SportsLibraryController::class, 'playerCareer'])->whereNumber('player');
        Route::get('coaches/{coach}', [SportsLibraryController::class, 'coach'])->whereNumber('coach');
        Route::get('matches/{match}/insights', [SportsLibraryController::class, 'insights']);
        Route::get('search', [SportsLibraryController::class, 'search'])->middleware('throttle:60,1');

        // Predictions, contests and prizes (196), rating (199), rooms and sharing (195).
        Route::get('matches/{match}/play', [SportsPlayController::class, 'play']);
        Route::post('matches/{match}/prediction', [SportsPlayController::class, 'predict'])->middleware('throttle:60,1');
        Route::post('matches/{match}/rating', [SportsPlayController::class, 'rate']);
        Route::post('matches/{match}/share', [SportsPlayController::class, 'share']);
        Route::post('matches/{match}/room', [SportsPlayController::class, 'room']);
        Route::get('contests', [SportsPlayController::class, 'contests']);
        Route::get('contests/{contest}', [SportsPlayController::class, 'contest']);
        Route::get('prizes', [SportsPlayController::class, 'prizes']);
    });
});
