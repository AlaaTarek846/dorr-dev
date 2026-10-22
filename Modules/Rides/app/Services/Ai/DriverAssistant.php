<?php

namespace Modules\Rides\Services\Ai;

use Carbon\CarbonImmutable;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverDailyStat;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Dispatch\DemandInsights;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\LearnedEta;
use Modules\Rides\Support\Geo;

/**
 * DORR Driver AI (Block 06 phase 10, D-394) — the captain's assistant, on their DORR AI plan:
 *
 *  - where to work: the best opportunity around them now, or what the next hour usually brings (AI-02);
 *  - a clash: a booking they reserved that the ride they're on, or where they are, may make them late
 *    for, or two of their bookings too close together;
 *  - their daily earnings goal: how far they are and roughly how many trips are left at today's average.
 *
 * Every card says why and how sure it is (`confidence` 0–1), and none decides anything: no rule of
 * eligibility or work is bypassed, nothing is accepted or moved for them. `ask()` lets them ask DORR AI
 * about their work, with these cards as its only facts.
 */
class DriverAssistant
{
    public function __construct(
        private readonly RideAi $ai,
        private readonly DemandInsights $demand,
        private readonly RideFeatures $features,
    ) {}

    /** @return array{allowed: bool, reason: ?string, cards: list<array<string, mixed>>, goal_minor: ?int} */
    public function cards(Driver $driver, ?float $lat, ?float $lng): array
    {
        $this->ensureOn($driver);
        $plan = $this->ai->entitlement($driver);
        $goal = DriverPreference::query()->whereKey($driver->id)->value('daily_goal_minor');
        if (! $plan['allowed']) {
            return ['allowed' => false, 'reason' => $plan['reason'] ?? 'no_plan', 'cards' => [], 'goal_minor' => $goal === null ? null : (int) $goal];
        }
        $presence = DriverPresence::query()->find($driver->id);
        $lat ??= $presence?->lat !== null ? (float) $presence->lat : null;
        $lng ??= $presence?->lng !== null ? (float) $presence->lng : null;

        $cards = array_values(array_filter([
            ...$this->conflicts($driver, $lat, $lng),
            $lat === null || $lng === null ? null : $this->zone($driver, $lat, $lng),
            $this->goal($driver, $goal === null ? null : (int) $goal),
        ]));

        return ['allowed' => true, 'reason' => null, 'cards' => $cards, 'goal_minor' => $goal === null ? null : (int) $goal];
    }

    /** `POST ai/ask` — a question about their work, answered by DORR AI from the cards only. */
    public function ask(Driver $driver, string $question, ?float $lat, ?float $lng): array
    {
        $this->ensureOn($driver);
        $this->ai->refuseUnlessEntitled($driver);
        $cards = $this->cards($driver, $lat, $lng)['cards'];
        $lang = app()->getLocale() === 'ar' ? 'Arabic (simple, friendly)' : 'English';
        $system = implode("\n", [
            'You are DORR Driver AI, the assistant of a ride-hailing captain in the DORR app. Answer in '.$lang.', in at most 4 short sentences.',
            'Use ONLY these facts about the captain\'s work now (JSON): '.json_encode($cards, JSON_UNESCAPED_UNICODE),
            'Never promise earnings, never tell them to break a rule (speed, rest, eligibility, the airport line), never decide for them, and say when you are not sure.',
            'Answer with JSON only: {"answer": string, "confidence": number between 0 and 1}.',
        ]);
        $answer = $this->ai->ask($driver, $system, mb_substr(trim($question), 0, 400), 'rides.driver_ai');

        return [
            'answer' => is_string($answer['answer'] ?? null) ? trim($answer['answer']) : __('rides.ai.no_answer'),
            'confidence' => is_numeric($answer['confidence'] ?? null) ? max(0, min(1, round((float) $answer['confidence'], 2))) : null,
            'cards' => $cards,
        ];
    }

    private function ensureOn(Driver $driver): void
    {
        if ($driver->country_id === null || ! $this->features->allows((int) $driver->country_id, $driver->city_id, 'driver_ai')) {
            throw RidesException::featureDisabled('driver_ai');
        }
    }

    /** @return array<string, mixed>|null */
    private function zone(Driver $driver, float $lat, float $lng): ?array
    {
        $hot = $this->demand->hotZones($driver, $lat, $lng)[0] ?? null;
        if ($hot !== null && $hot['score'] >= 1.5 && $hot['distance_m'] > 500) {
            return [
                'type' => 'zone', 'title' => __('rides.ai.card_zone_title'),
                'text' => __('rides.ai.card_zone_now', ['km' => number_format($hot['distance_m'] / 1000, 1)]),
                'why' => $hot['why'],
                'confidence' => round(min(1, $hot['requests'] / 10), 2),
                'lat' => $hot['lat'], 'lng' => $hot['lng'],
            ];
        }
        foreach ($this->demand->forecast($driver, $lat, $lng) as $slot) {
            $zone = $slot['zones'][0] ?? null;
            if ($zone !== null && $zone['confidence'] >= 0.5 && $zone['distance_m'] > 500) {
                return [
                    'type' => 'zone', 'title' => __('rides.ai.card_zone_title'),
                    'text' => __('rides.ai.card_zone_soon', ['minutes' => $slot['in_min'], 'km' => number_format($zone['distance_m'] / 1000, 1)]),
                    'why' => __('rides.ai.card_zone_soon_why', ['expected' => $zone['expected'], 'weeks' => (int) round($zone['confidence'] * 4)]),
                    'confidence' => $zone['confidence'],
                    'lat' => $zone['lat'], 'lng' => $zone['lng'],
                ];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function conflicts(Driver $driver, ?float $lat, ?float $lng): array
    {
        $reserved = Ride::query()->where('reserved_driver_id', $driver->id)->where('status', RideStatus::Scheduled->value)
            ->where('scheduled_at', '>=', now())->where('scheduled_at', '<=', now()->addDay())->orderBy('scheduled_at')->get();
        if ($reserved->isEmpty()) {
            return [];
        }
        $cards = [];
        $tz = (string) config('rides.default_timezone', 'Asia/Riyadh');
        // Two of my bookings too close together.
        foreach ($reserved->values() as $i => $ride) {
            $next = $reserved->values()[$i + 1] ?? null;
            if ($next !== null) {
                $needs = (int) ($ride->planned_duration_s ?? 1800) + (int) $ride->policy('scheduled.overlap_gap_s', 1800);
                if ($ride->scheduled_at->diffInSeconds($next->scheduled_at) < $needs) {
                    $cards[] = [
                        'type' => 'conflict', 'title' => __('rides.ai.card_conflict_title'),
                        'text' => __('rides.ai.card_conflict_two', ['a' => CarbonImmutable::parse($ride->scheduled_at)->setTimezone($tz)->format('H:i'), 'b' => CarbonImmutable::parse($next->scheduled_at)->setTimezone($tz)->format('H:i')]),
                        'why' => __('rides.ai.card_conflict_two_why'), 'confidence' => 0.9, 'ride_uuid' => $next->uuid,
                    ];
                }
            }
        }
        // The next booking soon: can I make it from where I am / after the ride I'm on?
        $first = $reserved->first();
        $left = (int) now()->diffInSeconds($first->scheduled_at, false);
        if ($left <= 7200 && $lat !== null && $lng !== null) {
            $speed = LearnedEta::speedKmh($first) / 3.6;
            $current = Ride::query()->where('driver_id', $driver->id)->where('status', RideStatus::InProgress->value)->first();
            $from = $current !== null && $current->destination_lat !== null ? [(float) $current->destination_lat, (float) $current->destination_lng] : [$lat, $lng];
            $finish = $current === null ? 0 : (int) ($current->planned_duration_s ?? 0) - (int) ($current->started_at?->diffInSeconds(now()) ?? 0);
            $travel = (int) (Geo::metres($from[0], $from[1], $first->origin_lat, $first->origin_lng) * 1.3 / $speed);
            if (max(0, $finish) + $travel > $left) {
                $cards[] = [
                    'type' => 'conflict', 'title' => __('rides.ai.card_conflict_title'),
                    'text' => __('rides.ai.card_conflict_late', ['time' => CarbonImmutable::parse($first->scheduled_at)->setTimezone($tz)->format('H:i'), 'minutes' => (int) ceil((max(0, $finish) + $travel - $left) / 60)]),
                    'why' => __($current !== null ? 'rides.ai.card_conflict_late_ride' : 'rides.ai.card_conflict_late_far'),
                    'confidence' => 0.7, 'ride_uuid' => $first->uuid,
                ];
            }
        }

        return $cards;
    }

    /** @return array<string, mixed>|null */
    private function goal(Driver $driver, ?int $goal): ?array
    {
        if ($goal === null || $goal <= 0) {
            return null;
        }
        $today = DriverDailyStat::query()->where('driver_id', $driver->id)->where('stat_date', now((string) config('rides.default_timezone', 'Asia/Riyadh'))->toDateString())->first();
        $net = (int) ($today?->net_minor ?? 0) + (int) ($today?->tips_minor ?? 0);
        $trips = (int) ($today?->trips ?? 0);
        $left = max(0, $goal - $net);
        $average = $trips > 0 ? intdiv($net, $trips) : null;

        return [
            'type' => 'goal', 'title' => __('rides.ai.card_goal_title'),
            'goal_minor' => $goal, 'earned_minor' => $net, 'left_minor' => $left,
            'progress' => round(min(1, $net / $goal), 2),
            'trips_left' => $left === 0 || $average === null || $average <= 0 ? null : (int) ceil($left / $average),
            'text' => $left === 0 ? __('rides.ai.card_goal_done') : ($average === null ? __('rides.ai.card_goal_start') : __('rides.ai.card_goal_left', ['trips' => (int) ceil($left / max(1, $average))])),
            'why' => $average === null ? __('rides.ai.card_goal_why_none') : __('rides.ai.card_goal_why', ['trips' => $trips]),
            'confidence' => $trips >= 5 ? 0.8 : ($trips >= 2 ? 0.5 : 0.3),
        ];
    }
}
