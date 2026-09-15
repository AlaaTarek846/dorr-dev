<?php

use App\Http\Resources\PlatformBrandingResource;
use App\Repositories\LanguageRepository;
use App\Repositories\PlatformSettingRepository;
use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserSocialAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('auth/user')->group(function () {
    Route::get('{provider}/redirect', [UserSocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'apple']);
    Route::get('{provider}/callback', [UserSocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'apple']);
});

Route::get('/admin/{any?}', function () {
    $setting = app(PlatformSettingRepository::class)->instance();
    $branding = (new PlatformBrandingResource($setting))->resolve(request());
    $defaultDashboardLocale = app(LanguageRepository::class)->defaultDashboardLocale();

    return view('admin', [
        'branding' => $branding,
        'appName' => $branding['app_name'] ?: config('app.name', 'Laravel'),
        'defaultDashboardLocale' => $defaultDashboardLocale,
    ]);
})->where('any', '.*')->name('admin');

Route::get('/user/{any?}', function () {
    $setting = app(PlatformSettingRepository::class)->instance();
    $branding = (new PlatformBrandingResource($setting))->resolve(request());
    $defaultDashboardLocale = app(LanguageRepository::class)->defaultDashboardLocale();

    return view('user', [
        'branding' => $branding,
        'appName' => $branding['app_name'] ?: config('app.name', 'Laravel'),
        'defaultDashboardLocale' => $defaultDashboardLocale,
    ]);
})->where('any', '.*')->name('user');
