<?php

use App\Http\Resources\General\PlatformBrandingResource;
use App\Repositories\General\LanguageRepository;
use App\Repositories\General\PlatformSettingRepository;
use App\Support\Dashboard\DashboardThemeResolver;
use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\ProviderSocialAuthController;
use Modules\User\Http\Controllers\UserSocialAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/mobile-app-preview', function () {
    return view('mobile-app-preview');
});

Route::prefix('auth/user')->group(function () {
    Route::get('{provider}/redirect', [UserSocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'apple']);
    Route::get('{provider}/callback', [UserSocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'apple']);
});

Route::prefix('auth/provider')->group(function () {
    Route::get('{provider}/redirect', [ProviderSocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'apple']);
    Route::get('{provider}/callback', [ProviderSocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'apple']);
});

$dashboardSpaViewData = function (): array {
    $setting = app(PlatformSettingRepository::class)->instance();
    $branding = (new PlatformBrandingResource($setting))->resolve(request());
    $defaultDashboardLocale = app(LanguageRepository::class)->defaultDashboardLocale();

    return [
        'branding' => $branding,
        'appName' => $branding['app_name'] ?: config('app.name', 'Laravel'),
        'defaultDashboardLocale' => $defaultDashboardLocale,
        'dashboardTheme' => app(DashboardThemeResolver::class)->context(),
    ];
};

// "Click to chat" links from the DORR chat (opens the app with a message ready).
// A group / channel invite link: opens the join sheet in the app.
Route::get('/j/{token}', \Modules\Chat\Http\Controllers\General\ChatInvitePageController::class)->where('token', '[A-Za-z0-9]{8,64}');
Route::get('/call/{token}', \Modules\Chat\Http\Controllers\General\ChatCallLinkPageController::class)->where('token', '[A-Za-z0-9]{8,64}');
Route::get('/c/{token}', \Modules\Chat\Http\Controllers\General\ChatLinkPageController::class)->where('token', '[A-Za-z0-9_-]{8,80}');

// A ride shared with family (docs/modules/rides/README.md → Safety): the live page and what it polls.
Route::get('/ride/{token}', [\Modules\Rides\Http\Controllers\RideSharePageController::class, 'page'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1');
Route::get('/ride/{token}/live', [\Modules\Rides\Http\Controllers\RideSharePageController::class, 'live'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');

Route::get('/admin/{any?}', function () use ($dashboardSpaViewData) {
    return view('admin', $dashboardSpaViewData());
})->where('any', '.*')->name('admin');

Route::get('/user/{any?}', function () use ($dashboardSpaViewData) {
    return view('user', $dashboardSpaViewData());
})->where('any', '.*')->name('user');

Route::get('/provider/{any?}', function () use ($dashboardSpaViewData) {
    return view('provider', $dashboardSpaViewData());
})->where('any', '.*')->name('provider');
