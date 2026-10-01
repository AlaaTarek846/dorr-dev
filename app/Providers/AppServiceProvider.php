<?php

namespace App\Providers;

use App\Support\Translations\PublishedTranslations;
use App\Support\Translations\TranslationLoader;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PublishedTranslations::class);

        $this->app->extend('translation.loader', fn (Loader $loader, $app) => new TranslationLoader(
            $loader,
            $app->make(PublishedTranslations::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('apple', AppleProvider::class);
        });
    }
}
