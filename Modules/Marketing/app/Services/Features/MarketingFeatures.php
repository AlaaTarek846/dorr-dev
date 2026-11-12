<?php

namespace Modules\Marketing\Services\Features;

use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Marketing\Models\MarketingFeatureRule;

/**
 * Every marketing feature is switched on or off by the admin, per country (D4), like Freelance: **no rule means off**,
 * nothing works in a country unless `marketing` itself is on there, and the dashboard's service-availability page can
 * close the whole service in a country (an explicit "service off" on the `marketing` service row).
 */
class MarketingFeatures
{
    private const CACHE = 'marketing:feature-rules';

    public const CLOSED_CACHE = 'marketing:service-closed';

    public const SERVICE = 'marketing';

    public const FEATURES = ['research', 'plans', 'finance', 'export', 'team', 'calendar', 'content', 'budgets', 'results', 'platforms', 'campaigns', 'platform_meta', 'optimisation'];

    public static function all(): array
    {
        return [self::SERVICE, ...self::FEATURES];
    }

    public function allows(int $countryId, string $feature): bool
    {
        if ($this->closedByAvailability($countryId)) {
            return false;
        }

        if ($feature !== self::SERVICE && ! $this->rule($countryId, self::SERVICE)) {
            return false;
        }

        return $this->rule($countryId, $feature);
    }

    /**
     * What the apps show in this country: the service and each feature.
     *
     * @return array{service: bool, features: array<string, bool>}
     */
    public function snapshot(int $countryId): array
    {
        $service = $this->rule($countryId, self::SERVICE) && ! $this->closedByAvailability($countryId);

        return [
            'service' => $service,
            'features' => collect(self::FEATURES)->mapWithKeys(fn (string $f) => [$f => $service && $this->rule($countryId, $f)])->all(),
        ];
    }

    public static function forget(): void
    {
        foreach ([Cache::store(), Cache::memo()] as $store) {
            $store->forget(self::CACHE);
            $store->forget(self::CLOSED_CACHE);
        }
    }

    public function closedByAvailability(int $countryId): bool
    {
        return in_array($countryId, Cache::memo()->remember(self::CLOSED_CACHE, 300, fn () => ServiceAvailability::query()
            ->whereNull('region_id')->where('service_enabled', false)
            ->whereIn('service_category_id', ServiceCategory::query()->where('module_name', config('marketing.service_module_name', 'marketing'))->select('id'))
            ->pluck('country_id')->map(fn ($id) => (int) $id)->all()), true);
    }

    private function rule(int $countryId, string $feature): bool
    {
        return (bool) $this->rules()->first(fn (MarketingFeatureRule $r) => $r->country_id === $countryId && $r->feature === $feature)?->enabled;
    }

    /** @return Collection<int, MarketingFeatureRule> */
    private function rules(): Collection
    {
        return Cache::memo()->remember(self::CACHE, 300, fn () => MarketingFeatureRule::query()->get());
    }
}
