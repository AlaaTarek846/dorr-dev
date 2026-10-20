<?php

namespace Modules\Rides\Services\Features;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;

/**
 * Every ride service and feature is switched on or off by the admin, per country and city
 * (docs/rides-plan.md §5) — the same rule shape as the chat's, with one difference: **no rule means
 * off**. A city rule beats the country rule. Nothing works unless `rides` itself is on there.
 */
class RideFeatures
{
    private const CACHE = 'rides:feature-rules';

    public const SERVICE = 'rides';

    /** Features with a switch besides `rides` and `category.{slug}`. */
    public const FEATURES = [
        'bidding', 'multi_stop', 'open_destination', 'scheduled', 'book_for_other', 'cash', 'wallet', 'card',
        'tips', 'pin', 'share', 'change_driver', 'driver_points', 'one_touch', 'change_destination', 'coupons',
        'pickup_points', 'airport_queue', 'restricted_zones', 'saved_places', 'favorite_circle',
        'auto_accept', 'cases',
        // O-26c, O-26b: services of their own inside rides (no category).
        'find_driver', 'driving_lessons',
    ];

    public static function categoryFeature(string $slug): string
    {
        return 'category.'.$slug;
    }

    public function allows(int $countryId, ?int $cityId, string $feature): bool
    {
        if ($feature !== self::SERVICE && ! $this->rule($countryId, $cityId, self::SERVICE)) {
            return false;
        }

        return $this->rule($countryId, $cityId, $feature);
    }

    /**
     * What the app shows at this place: the service, each active category, each feature.
     *
     * @return array{service: bool, categories: array<string, bool>, features: array<string, bool>}
     */
    public function snapshot(int $countryId, ?int $cityId): array
    {
        $service = $this->rule($countryId, $cityId, self::SERVICE);
        $slugs = RideCategory::query()->where('status', true)->orderBy('sort_order')->pluck('slug');

        return [
            'service' => $service,
            'categories' => $slugs->mapWithKeys(fn (string $slug) => [$slug => $service && $this->rule($countryId, $cityId, self::categoryFeature($slug))])->all(),
            'features' => collect(self::FEATURES)->mapWithKeys(fn (string $f) => [$f => $service && $this->rule($countryId, $cityId, $f)])->all(),
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    private function rule(int $countryId, ?int $cityId, string $feature): bool
    {
        $rules = $this->rules()->where('feature', $feature)->where('country_id', $countryId);

        if ($cityId !== null && ($city = $rules->first(fn (RideFeatureRule $r) => $r->city_id === $cityId)) !== null) {
            return $city->enabled;
        }

        return (bool) $rules->first(fn (RideFeatureRule $r) => $r->city_id === null)?->enabled;
    }

    /** @return Collection<int, RideFeatureRule> */
    private function rules(): Collection
    {
        return Cache::remember(self::CACHE, 300, fn () => RideFeatureRule::query()->get());
    }
}
