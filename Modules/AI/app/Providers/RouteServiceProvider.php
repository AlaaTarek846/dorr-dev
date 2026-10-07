<?php

namespace Modules\AI\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'AI';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
        $this->mapSiteRoutes();
        $this->mapHostedSiteRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }

    /**
     * Generated customer sites: no middleware group, and either their own
     * domain (AI_SITES_DOMAIN - recommended in production, gives each site
     * real browser isolation from the app) or a /ai-sites prefix.
     */
    protected function mapSiteRoutes(): void
    {
        $domain = config('ai.sites.domain');

        $route = Route::middleware([])->name('ai-sites.');

        if (filled($domain)) {
            $route->domain($domain);
        } else {
            $route->prefix('ai-sites');
        }

        $route->group(module_path($this->name, '/routes/sites.php'));
    }

    /**
     * Hosted sites without wildcard DNS: /sites/{name}. When a hosting domain is
     * set, ServeHostedSiteMiddleware answers instead and this is not registered.
     */
    protected function mapHostedSiteRoutes(): void
    {
        if (filled(config('ai.sites.hosting.domain'))) {
            return;
        }

        Route::middleware(['throttle:240,1'])
            ->prefix((string) config('ai.sites.hosting.path_prefix', 'sites'))
            ->get('{name}/{path?}', [\Modules\AI\Http\Controllers\AiSiteServeController::class, 'hosted'])
            ->where('name', '[a-z0-9-]{3,63}')
            ->where('path', '.*')
            ->name('ai-sites-hosted.show');
    }
}
