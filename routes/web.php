<?php

use App\Http\Resources\PlatformBrandingResource;
use App\Repositories\LanguageRepository;
use App\Repositories\PlatformSettingRepository;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
