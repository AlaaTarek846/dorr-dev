<?php

namespace Modules\Sms\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Sms';

    /**
     * Called before routes are registered.
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
        $this->mapAdminRoutes();
    }

    /**
     * Admin API routes for the SMS module (mounted under admin/v1).
     */
    protected function mapAdminRoutes(): void
    {
        Route::middleware('locale')
            ->prefix('api/admin/v1')
            ->group(module_path($this->name, '/routes/admin.php'));
    }
}
