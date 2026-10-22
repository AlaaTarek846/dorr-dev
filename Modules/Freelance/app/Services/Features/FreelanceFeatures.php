<?php

namespace Modules\Freelance\Services\Features;

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
        $service = $this->rule($countryId, self::SERVICE);

        return [
            'service' => $service,
            'features' => collect(self::FEATURES)->mapWithKeys(fn (string $f) => [$f => $service && $this->rule($countryId, $f)])->all(),
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
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
