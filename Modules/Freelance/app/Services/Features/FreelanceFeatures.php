<?php

namespace Modules\Freelance\Services\Features;

use App\Models\ServiceAvailability;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Freelance\Models\FreelanceFeatureRule;

/**
 * Every freelance feature is switched on or off by the admin, per country (docs/freelance_tasks.md
 * FL-002) — the same idea as RideFeatures, without cities: **no rule means off**, and nothing
 * works in a country unless `freelance` itself is on there.
 */
class FreelanceFeatures
{
    private const CACHE = 'freelance:feature-rules';

    private const CLOSED_CACHE = 'freelance:service-closed';

    public const SERVICE = 'freelance';

    /** Features with a switch besides `freelance`; each batch of tasks adds its own. */
    public const FEATURES = [
        'projects', 'invitations', 'attachments',
        'bidding', 'free_offer_quota',
        'milestones', 'chat',
        'wallet_payment', 'client_fee', 'commission',
        'ai_assistant', 'disputes', 'ratings',
    ];

    /** The service first, then the features — what the admin switches page lists. */
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
        Cache::forget(self::CACHE);
        Cache::forget(self::CLOSED_CACHE);
    }

    /**
     * FL-804: the dashboard's `service-availability` page can close the Freelance service in a whole country. Only an explicit
     * "service off" on the country counts, so a country nobody touched there keeps following its freelance switches.
     */
    public function closedByAvailability(int $countryId): bool
    {
        return in_array($countryId, Cache::remember(self::CLOSED_CACHE, 300, fn () => ServiceAvailability::query()
            ->whereNull('region_id')->where('service_enabled', false)
            ->whereIn('service_category_id', ServiceCategory::query()->where('module_name', config('freelance.service_module_name', 'freelance'))->select('id'))
            ->pluck('country_id')->map(fn ($id) => (int) $id)->all()), true);
    }

    private function rule(int $countryId, string $feature): bool
    {
        return (bool) $this->rules()->first(fn (FreelanceFeatureRule $r) => $r->country_id === $countryId && $r->feature === $feature)?->enabled;
    }

    /** @return Collection<int, FreelanceFeatureRule> */
    private function rules(): Collection
    {
        return Cache::remember(self::CACHE, 300, fn () => FreelanceFeatureRule::query()->get());
    }
}
