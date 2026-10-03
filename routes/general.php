<?php

use App\Http\Controllers\General\Public\GeneralController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| General (public) API — /api/general/v1
|--------------------------------------------------------------------------
|
| No auth middleware. Shared by mobile apps and pre-login dashboard flows.
| Loaded from routes/api.php (Laravel applies /api prefix + api middleware).
|
*/

Route::middleware('locale')
    ->prefix('general/v1')
    ->name('general.')
    ->group(function () {
        Route::get('countries/dropdown', [GeneralController::class, 'countriesDropdown']);
        Route::get('countries/detect', [GeneralController::class, 'countriesDetect']);
        Route::get('languages/dropdown', [GeneralController::class, 'languagesDropdown']);
        Route::get('platform-settings/branding', [GeneralController::class, 'platformBranding']);
        Route::get('mobile-appearance-defaults', [GeneralController::class, 'mobileAppearanceDefaults']);
        Route::get('services', [GeneralController::class, 'services']);
        Route::get('translations/languages', [GeneralController::class, 'translationLanguages']);
        Route::get('translations/{code}/vue', [GeneralController::class, 'vueTranslations'])
            ->where('code', '[A-Za-z]{2,3}');
        Route::get('translations/{code}/android', [GeneralController::class, 'androidTranslations'])
            ->where('code', '[A-Za-z]{2,3}');
    });
