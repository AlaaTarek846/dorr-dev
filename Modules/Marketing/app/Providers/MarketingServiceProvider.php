<?php

namespace Modules\Marketing\Providers;

use Modules\Marketing\Checkout\MarketingPackagePurpose;
use Modules\Marketing\Services\Research\Contracts\SearchDriver;
use Modules\Marketing\Services\Research\Drivers\NullDriver;
use Modules\Marketing\Services\Research\Drivers\TavilyDriver;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR AI Marketing Director (docs/marketing_tasks.md): projects with a team, sourced research, business /
 * marketing / GTM plans with versions and approvals, financial scenarios computed by code.
 */
class MarketingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Marketing';

    protected string $nameLower = 'marketing';

    /** @var string[] */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /** No views (API only). */
    protected function registerViews(): void {}

    public function register(): void
    {
        parent::register();

        $this->app->bind(SearchDriver::class, fn () => match (config('marketing.research.driver')) {
            'tavily' => new TavilyDriver,
            default => new NullDriver,
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(CheckoutPurposes::class)->register(MarketingPackagePurpose::KEY, MarketingPackagePurpose::class);
    }
}
