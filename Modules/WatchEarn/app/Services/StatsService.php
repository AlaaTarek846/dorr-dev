<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdView;

/**
 * What the advertiser sees (WE-021 audience estimate, WE-024 stats). Counts only — never who watched (SC-18), and
 * never the platform/viewer split of the price.
 */
class StatsService
{
    /**
     * Roughly how many people an audience reaches: accounts of the country (where they last signed in from, else
     * where they registered), in one of the cities (by the city of their last sign-in), of the gender and language.
     *
     * @param  list<int>  $cities
     */
    public function audience(Country $country, array $cities, ?string $gender, ?string $locale, ?int $exceptUserId = null): int
    {
        return User::query()
            ->where(fn (Builder $q) => $q->where('logged_in_country_id', $country->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('logged_in_country_id')->where('country_id', $country->id)))
            ->when($cities !== [], fn (Builder $q) => $q->whereIn('geo_city_id', $cities))
            ->when($gender, fn (Builder $q) => $q->where('gender', $gender))
            ->when($locale, fn (Builder $q) => $q->where('locale', $locale))
            ->when($exceptUserId, fn (Builder $q) => $q->whereKeyNot($exceptUserId))
            ->count();
    }

    /** @return array<string, mixed> */
    public function forAd(Ad $ad): array
    {
        $completed = AdView::query()->where('ad_id', $ad->id)->whereNotNull('completed_at');
        $days = max(1, (int) ceil($ad->starts_at->diffInDays(min(now(), $ad->ends_at)) ?: 1));

        return [
            'totals' => [
                'impressions' => $ad->impressions_count,
                'completed' => $ad->completed_count,
                'completion_rate' => $this->rate($ad->completed_count, $ad->impressions_count),
                'interactions' => $ad->interactions_count,
                'interaction_rate' => $this->rate($ad->interactions_count, $ad->completed_count),
                'comments' => $ad->comments_count,
                'reactions' => $ad->reactions_count,
                'reports' => $ad->reports_count,
                'unique_viewers' => (clone $completed)->count(),
            ],
            'money' => [
                'budget_minor' => $ad->budget_minor,
                'spent_minor' => $ad->spent_minor,
                'remaining_minor' => $ad->reservedMinor(),
                'price_per_view_minor' => $ad->price_per_view_minor,
                'views_left' => $ad->viewsLeft(),
                'cost_per_interaction_minor' => $ad->interactions_count > 0 ? intdiv($ad->spent_minor, $ad->interactions_count) : null,
                'spent_percent' => $this->rate($ad->spent_minor, $ad->budget_minor),
                'daily_average_minor' => intdiv($ad->spent_minor, $days),
            ],
            'daily' => $this->daily($ad),
            'by_city' => $this->byCity($ad),
            'by_gender' => $this->groupCount($ad, 'gender'),
            'by_platform' => $this->groupCount($ad, 'platform'),
            'by_hour' => $this->byHour($ad),
            'reactions' => DB::table('we_reactions')->where('ad_id', $ad->id)->selectRaw('emoji, COUNT(*) n')->groupBy('emoji')->orderByDesc('n')
                ->pluck('n', 'emoji')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** Completed views and interactions per day, from the start to today (or the end). @return list<array{date: string, completed: int, interactions: int}> */
    private function daily(Ad $ad): array
    {
        $completed = AdView::query()->where('ad_id', $ad->id)->whereNotNull('completed_at')
            ->selectRaw('DATE(completed_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $interactions = AdView::query()->where('ad_id', $ad->id)->whereNotNull('interacted_at')
            ->selectRaw('DATE(interacted_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        $out = [];
        $from = $ad->starts_at->copy()->startOfDay();
        $to = min(now(), $ad->ends_at)->copy()->startOfDay();
        for ($day = $from; $day->lte($to) && count($out) < 120; $day = $day->copy()->addDay()) {
            $key = $day->toDateString();
            $out[] = ['date' => $key, 'completed' => (int) ($completed[$key] ?? 0), 'interactions' => (int) ($interactions[$key] ?? 0)];
        }

        return $out;
    }

    /** @return list<array{city_id: ?int, name: ?string, completed: int}> */
    private function byCity(Ad $ad): array
    {
        $rows = AdView::query()->where('ad_id', $ad->id)->whereNotNull('completed_at')
            ->selectRaw('city_id, COUNT(*) n')->groupBy('city_id')->orderByDesc('n')->get();

        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        $names = class_exists($class)
            ? $class::query()->whereIn('id', $rows->pluck('city_id')->filter())->with('translations')->get()
                ->mapWithKeys(fn (Model $c) => [$c->getKey() => $c->translatedName()])
            : collect();

        return $rows->map(fn ($r) => ['city_id' => $r->city_id ? (int) $r->city_id : null, 'name' => $r->city_id ? ($names[$r->city_id] ?? null) : null, 'completed' => (int) $r->n])->all();
    }

    /** @return array<string, int> */
    private function groupCount(Ad $ad, string $column): array
    {
        return AdView::query()->where('ad_id', $ad->id)->whereNotNull('completed_at')
            ->selectRaw("COALESCE({$column}, 'unknown') k, COUNT(*) n")->groupBy('k')
            ->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }

    /** Completed views by hour of the day (0–23), in the app's time zone. @return list<int> */
    private function byHour(Ad $ad): array
    {
        $hours = array_fill(0, 24, 0);
        AdView::query()->where('ad_id', $ad->id)->whereNotNull('completed_at')->pluck('completed_at')
            ->each(function ($at) use (&$hours) {
                $hours[(int) $at->format('G')]++;
            });

        return $hours;
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }
}
