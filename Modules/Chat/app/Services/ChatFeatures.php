<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Chat\Models\ChatFeatureRule;
use Modules\Chat\Models\ChatSetting;

/**
 * Which chat features someone may use where they are (spec "أمان 2" and the shared requirement
 * 10 — by country and city for now). The admin's switch for the whole feature comes first; then
 * a rule for the city the phone is in beats a rule for its country; no rule means on. Calls carry
 * voice rooms and lives with them: where calls are off, so are they.
 *
 * The city is the admin's (Discover) city nearest the phone, learned when the app sends its
 * location — only the city is kept, never the coordinates, and only for CITY_DAYS.
 */
final class ChatFeatures
{
    public const CITY_DAYS = 30;

    /** Farther than this from every city: no city (country rules only). */
    public const CITY_RADIUS_KM = 60;

    private const CACHE = 'chat:feature-rules';

    public function __construct(private readonly ChatPrivacy $privacy) {}

    public function allows(?Model $account, string $feature): bool
    {
        $settings = ChatSetting::current();
        $on = match ($feature) {
            'calls', 'voice_rooms', 'live' => (bool) $settings->calls_enabled,
            'stories' => (bool) ($settings->stories_enabled ?? true),
            'ai' => $settings->aiEnabled(),
            default => true,
        };

        return $on && $this->allowsHere($account, $feature);
    }

    /**
     * Only the country and city rules (the admin's switch for the whole feature keeps its own
     * check and error where it already had one).
     */
    public function allowsHere(?Model $account, string $feature): bool
    {
        if (in_array($feature, ['voice_rooms', 'live'], true) && ! $this->ruleAllows($account, 'calls')) {
            return false;
        }

        return $this->ruleAllows($account, $feature);
    }

    /**
     * Everything at once, for the app (it hides what's off) — and the city it was decided for.
     *
     * @return array{features: array<string, bool>, city: ?array{id: int, name: ?string}}
     */
    public function forAccount(?Model $account): array
    {
        $features = [];
        foreach (ChatFeatureRule::FEATURES as $feature) {
            $features[$feature] = $this->allows($account, $feature);
        }
        $cityId = $account ? $this->cityOf($account) : null;

        return ['features' => $features, 'city' => $cityId ? $this->cityCard($cityId) : null];
    }

    /** The phone is here: keep the nearest city (or none), never the coordinates. */
    public function locate(Model $me, float $latitude, float $longitude): void
    {
        $this->privacy->settingsFor($me)->forceFill([
            'city_id' => $this->nearestCity($latitude, $longitude)?->getKey(),
            'city_seen_at' => now(),
        ])->save();
    }

    public function cityOf(Model $account): ?int
    {
        $settings = $this->privacy->peek($account);
        if ($settings->city_id === null || $settings->city_seen_at === null || $settings->city_seen_at->lt(now()->subDays(self::CITY_DAYS))) {
            return null;
        }

        return (int) $settings->city_id;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    private function ruleAllows(?Model $account, string $feature): bool
    {
        $country = $account?->getAttribute('country_id');
        $city = $account ? $this->cityOf($account) : null;
        $rules = $this->rules()->where('feature', $feature);

        if ($city !== null && ($rule = $rules->first(fn (ChatFeatureRule $r) => $r->city_id === $city)) !== null) {
            return $rule->enabled;
        }
        if ($country !== null && ($rule = $rules->first(fn (ChatFeatureRule $r) => $r->city_id === null && $r->country_id === (int) $country)) !== null) {
            return $rule->enabled;
        }
        // The earlier list of countries without calls (chat settings) still counts.
        if ($feature === 'calls' && $country !== null) {
            return ! in_array((int) $country, array_map('intval', ChatSetting::current()->calls_disabled_countries ?? []), true);
        }

        return true;
    }

    /** @return Collection<int, ChatFeatureRule> */
    private function rules(): Collection
    {
        return Cache::remember(self::CACHE, 300, fn () => ChatFeatureRule::query()->get());
    }

    /** Discover's cities are the admin's list of cities; without Discover there are no city rules. */
    private function cityModel(): ?string
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';

        return class_exists($class) ? $class : null;
    }

    private function nearestCity(float $latitude, float $longitude): ?Model
    {
        $class = $this->cityModel();
        if ($class === null) {
            return null;
        }

        return $class::query()->where('status', true)->whereNotNull('lat')->whereNotNull('lng')->get()
            ->map(fn (Model $city) => [$city, self::km($latitude, $longitude, (float) $city->lat, (float) $city->lng)])
            ->filter(fn (array $pair) => $pair[1] <= self::CITY_RADIUS_KM)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    /** @return array{id: int, name: ?string}|null */
    private function cityCard(int $id): ?array
    {
        $class = $this->cityModel();
        $city = $class ? $class::query()->with('translations')->find($id) : null;

        return $city ? ['id' => (int) $city->getKey(), 'name' => $city->translatedName()] : null;
    }

    /** Great-circle distance in km. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
