<?php

namespace Modules\Rides\Services\Stats;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCancelReason;
use Modules\Rides\Models\RideRatingTag;
use Modules\Rides\Services\Ai\RideAi;

/**
 * AI-19 and «السلوك وجودة الخدمة» (Block 06 phase 9) — why captains arrive late and why riders rate low,
 * for the team: arrivals against the time promised on the request card, low ratings and the tags riders
 * picked with them, cancellations by reason, per city and per captain. `explain()` asks DORR AI to read
 * the figures and name the likely causes and what to try — DORR's own work, nobody's plan.
 */
class ServiceQuality
{
    /** Later than the promise by more than this counts as late. */
    private const LATE_GRACE_S = 120;

    public function __construct(private readonly RideAi $ai) {}

    /** @return array<string, mixed> */
    public function report(int $days, ?int $countryId, ?int $cityId): array
    {
        $from = now()->subDays($days);
        $scope = fn ($q) => $q->where('rides.created_at', '>=', $from)
            ->when($countryId !== null, fn ($q) => $q->where('rides.country_id', $countryId))
            ->when($cityId !== null, fn ($q) => $q->where('rides.city_id', $cityId));

        // Arrivals: the time the captain saw on the request card against when they really got there.
        $arrivals = DB::table('rides')->where($scope)->whereNotNull('rides.arrived_at')->whereNotNull('rides.accepted_at')
            ->join('ride_offers', fn ($j) => $j->on('ride_offers.ride_id', '=', 'rides.id')->where('ride_offers.status', 'accepted'))
            ->limit(20000)->get(['rides.id', 'rides.driver_id', 'rides.city_id', 'rides.accepted_at', 'rides.arrived_at', 'ride_offers.eta_s']);
        $late = $arrivals->map(function ($r) {
            $actual = strtotime((string) $r->arrived_at) - strtotime((string) $r->accepted_at);

            return ['driver_id' => (int) $r->driver_id, 'city_id' => $r->city_id, 'delay_s' => $actual - (int) $r->eta_s];
        });
        $lateOnes = $late->filter(fn (array $r) => $r['delay_s'] > self::LATE_GRACE_S);

        // Low ratings of captains by riders, and the tags chosen with them.
        $rideMorph = (new Ride)->getMorphClass();
        $ratings = DB::table('ratings')->where('ratings.rateable_type', $rideMorph)->where('ratings.author_type', 'user')
            ->join('rides', 'rides.id', '=', 'ratings.rateable_id')->where($scope)
            ->get(['ratings.id', 'ratings.stars', 'rides.driver_id', 'rides.city_id']);
        $low = $ratings->where('stars', '<=', 3);
        $tagCounts = DB::table('ride_rating_tag_selections')->whereIn('rating_id', $low->pluck('id'))
            ->selectRaw('tag_id, COUNT(*) as n')->groupBy('tag_id')->orderByDesc('n')->limit(10)->pluck('n', 'tag_id');
        $tags = RideRatingTag::query()->whereIn('id', $tagCounts->keys())->with('translations')->get()->keyBy('id');

        $cancels = DB::table('rides')->where($scope)->where('rides.status', RideStatus::Canceled->value)
            ->selectRaw('cancel_actor, cancel_reason_id, COUNT(*) as n')->groupBy('cancel_actor', 'cancel_reason_id')->orderByDesc('n')->limit(15)->get();
        $reasons = RideCancelReason::query()->whereIn('id', $cancels->pluck('cancel_reason_id')->filter())->with('translations')->get()->keyBy('id');

        $completed = DB::table('rides')->where($scope)->where('rides.status', RideStatus::Completed->value)->count();

        // Captains with at least 3 arrivals, the ones most often late first.
        $drivers = $late->groupBy('driver_id')->filter(fn ($rows) => $rows->count() >= 3)
            ->map(fn ($rows, $driverId) => [
                'driver_id' => (int) $driverId,
                'arrivals' => $rows->count(),
                'late' => $rows->where('delay_s', '>', self::LATE_GRACE_S)->count(),
                'late_share' => round($rows->where('delay_s', '>', self::LATE_GRACE_S)->count() / $rows->count(), 2),
                'avg_delay_s' => (int) round($rows->avg('delay_s')),
                'low_ratings' => $low->where('driver_id', (int) $driverId)->count(),
            ])->sortByDesc('late_share')->take(10)->values();
        $names = DB::table('drivers')->whereIn('id', $drivers->pluck('driver_id'))->pluck('name', 'id');

        $cities = $late->groupBy('city_id')->map(fn ($rows, $cityId) => [
            'city_id' => $cityId === '' ? null : (int) $cityId,
            'arrivals' => $rows->count(),
            'late_share' => round($rows->where('delay_s', '>', self::LATE_GRACE_S)->count() / max(1, $rows->count()), 2),
            'avg_rating' => ($r = $ratings->where('city_id', $cityId))->isEmpty() ? null : round($r->avg('stars'), 2),
        ])->sortByDesc('late_share')->values();
        $cityNames = \Modules\Discover\Models\DiscoverCity::query()->whereIn('id', $cities->pluck('city_id')->filter())->with('translations')->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->translated('name')]);

        return [
            'days' => $days,
            'completed' => $completed,
            'arrivals' => [
                'count' => $late->count(),
                'late' => $lateOnes->count(),
                'late_share' => $late->isEmpty() ? null : round($lateOnes->count() / $late->count(), 2),
                'avg_delay_s' => $lateOnes->isEmpty() ? 0 : (int) round($lateOnes->avg('delay_s')),
                'grace_s' => self::LATE_GRACE_S,
            ],
            'ratings' => [
                'count' => $ratings->count(),
                'avg' => $ratings->isEmpty() ? null : round($ratings->avg('stars'), 2),
                'low' => $low->count(),
                'low_share' => $ratings->isEmpty() ? null : round($low->count() / $ratings->count(), 2),
                'tags' => $tagCounts->map(fn ($n, $id) => ['tag' => $tags->get($id)?->translated('label') ?? (string) $id, 'count' => (int) $n])->values(),
            ],
            'cancels' => $cancels->map(fn ($c) => [
                'actor' => $c->cancel_actor,
                'reason' => $c->cancel_reason_id === null ? null : $reasons->get($c->cancel_reason_id)?->translated('label'),
                'count' => (int) $c->n,
            ])->values(),
            'cities' => $cities->map(fn (array $c) => $c + ['name' => $c['city_id'] === null ? null : ($cityNames[$c['city_id']] ?? null)])->values(),
            'drivers' => $drivers->map(fn (array $d) => $d + ['name' => $names[$d['driver_id']] ?? null])->values(),
        ];
    }

    /**
     * DORR AI's reading of the figures: the likely causes and what to try.
     *
     * @return array{summary: string, causes: list<string>, actions: list<string>}|null
     */
    public function explain(array $report): ?array
    {
        unset($report['drivers']); // no names to the model
        $system = implode("\n", [
            'You analyse service quality for a ride-hailing operations team (DORR). From the figures given, name the most likely causes of late arrivals and low ratings, and practical actions.',
            'Answer with ONE JSON object only: {"summary": string, "causes": [string], "actions": [string]} — in Arabic, short and concrete, at most 4 causes and 4 actions.',
            'Use only the figures; say so when there is too little data. Never blame a person by name.',
        ]);
        $answer = $this->ai->system($system, (string) json_encode($report, JSON_UNESCAPED_UNICODE), 'rides.quality');
        if ($answer === null) {
            return null;
        }

        return [
            'summary' => is_string($answer['summary'] ?? null) ? trim($answer['summary']) : '',
            'causes' => array_values(array_slice(array_filter((array) ($answer['causes'] ?? []), 'is_string'), 0, 4)),
            'actions' => array_values(array_slice(array_filter((array) ($answer['actions'] ?? []), 'is_string'), 0, 4)),
        ];
    }
}
