<?php

namespace Modules\Rides\Services\Drivers;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverPerformanceNote;
use Modules\Rides\Models\DriverRank;
use Modules\Rides\Models\DriverRankState;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * Captain ranks and performance (docs/rides_tasks.md D-5, D-6, D-239..D-259):
 *
 *  - The probation (`ranks.probation_days` from the first activation): the «new» rank, with the days left.
 *  - After it, the best rank whose thresholds the captain's numbers of the last `ranks.window_days` reach:
 *    trips, rating, cleanliness and behaviour (the riders' scores), on-time arrivals, completion, cancellations,
 *    warnings. Thresholds are the admin's (`driver_ranks`), never hard-coded in the apps.
 *  - Up: at once. Down: a warning first, with what slipped, and `ranks.grace_days` to put it right (D-253); then
 *    the lower rank, written on the record as a rank change that can be appealed (D-259, `ranks.appeal_allowed`).
 *  - A category can ask for a rank (`eligibility.min_rank`, e.g. VIP — D-254).
 */
class DriverRanks
{
    public function __construct(
        private readonly RidePolicies $policies,
        private readonly NotificationCenter $center,
    ) {}

    /** @return array{active: bool, days_left: int, ends_at: ?string} */
    public function probation(Driver $driver): array
    {
        $days = $this->policy($driver)->int('ranks.probation_days') ?: 30;
        $from = $driver->activated_at;
        if ($from === null) {
            return ['active' => true, 'days_left' => $days, 'ends_at' => null];
        }
        $ends = $from->copy()->addDays($days);

        return ['active' => $ends->isFuture(), 'days_left' => max(0, (int) ceil(now()->diffInHours($ends, false) / 24)), 'ends_at' => $ends->toIso8601String()];
    }

    /**
     * The captain's numbers over the window.
     *
     * @return array<string, int>
     */
    public function metrics(Driver $driver): array
    {
        $days = $this->policy($driver)->int('ranks.window_days') ?: 90;
        $since = now()->subDays($days);
        $rides = fn () => Ride::query()->where('driver_id', $driver->id)->where('accepted_at', '>=', $since);
        $accepted = $rides()->count();
        $completed = $rides()->where('status', RideStatus::Completed->value)->count();
        $cancelled = $rides()->where('status', RideStatus::Canceled->value)->where('cancel_actor', 'driver')->count();

        // The riders' stars and scores on this captain's rides.
        $rideIds = $rides()->where('status', RideStatus::Completed->value)->select('id');
        $ratings = DB::table('ratings')->where('rateable_type', (new Ride)->getMorphClass())->where('author_type', (new \Modules\User\Models\User)->getMorphClass())
            ->whereIn('rateable_id', $rideIds);
        $avg = (float) (clone $ratings)->avg('stars');
        $score = fn (string $criterion) => (float) DB::table('ride_rating_scores')->whereIn('rating_id', (clone $ratings)->select('id'))->where('criterion', $criterion)->avg('stars');

        // On time: arrived within the time promised when accepting (+ a grace).
        $grace = $this->policy($driver)->int('ranks.on_time_grace_s') ?: 120;
        $arrivals = DB::table('rides')->join('ride_offers', fn ($j) => $j->on('ride_offers.ride_id', '=', 'rides.id')->where('ride_offers.driver_id', $driver->id)->where('ride_offers.status', 'accepted'))
            ->where('rides.driver_id', $driver->id)->where('rides.accepted_at', '>=', $since)->whereNotNull('rides.arrived_at')
            ->get(['rides.accepted_at', 'rides.arrived_at', 'ride_offers.eta_s']);
        $onTime = $arrivals->filter(fn ($r) => strtotime((string) $r->arrived_at) - strtotime((string) $r->accepted_at) <= (int) $r->eta_s + $grace)->count();

        return [
            'trips' => $completed,
            'rating' => (int) round($avg * 100),
            'ratings_count' => (clone $ratings)->count(),
            'cleanliness' => (int) round($score('cleanliness') * 100),
            'behavior' => (int) round($score('behavior') * 100),
            'commitment_bp' => $arrivals->isEmpty() ? 10000 : intdiv($onTime * 10000, $arrivals->count()),
            'completion_bp' => $accepted === 0 ? 10000 : intdiv($completed * 10000, $accepted),
            'cancel_bp' => $accepted === 0 ? 0 : intdiv($cancelled * 10000, $accepted),
            'warnings' => DriverPerformanceNote::query()->where('driver_id', $driver->id)->where('kind', 'warning')->where('created_at', '>=', $since)
                ->where('appeal_status', '!=', 'accepted')->count(),
            'window_days' => $days,
        ];
    }

    /** Work the rank out again and act on it: up at once; down after a warning and its grace. */
    public function evaluate(Driver $driver): DriverRankState
    {
        $state = DriverRankState::query()->firstOrCreate(['driver_id' => $driver->id]);
        $metrics = $this->metrics($driver);
        $ranks = DriverRank::query()->where('status', true)->orderBy('sort_order')->get();
        $probationRank = $ranks->firstWhere('probation', true);
        $earned = $ranks->where('probation', false)->filter(fn (DriverRank $r) => $r->missing($metrics) === [])->last()
            ?? $ranks->where('probation', false)->first();
        $target = $this->probation($driver)['active'] && $probationRank !== null ? $probationRank : $earned;
        $current = $state->rank;

        $state->metrics = $metrics;
        $state->computed_at = now();
        if ($target === null) {
            $state->save();

            return $state;
        }

        if ($current === null || $current->probation || $target->sort_order >= $current->sort_order) {
            // Up (or the first rank, or out of probation): at once, and any pending fall is off.
            $up = $current !== null && $target->id !== $current->id;
            $state->fill(['rank_id' => $target->id, 'falling_to_id' => null, 'downgrade_due_at' => null])->save();
            if ($up) {
                $this->center->send($driver, 'driver.rank', 'driver_rank_up_title', 'driver_rank_up_body', ['rank' => (string) $target->localized('name')], ['type' => 'driver_rank']);
            }
        } elseif ($state->downgrade_due_at === null) {
            // Down: the warning first (D-253), with what slipped.
            $grace = $this->policy($driver)->int('ranks.grace_days') ?: 7;
            $state->fill(['falling_to_id' => $target->id, 'downgrade_due_at' => now()->addDays($grace)])->save();
            $this->center->send($driver, 'driver.rank', 'driver_rank_warning_title', 'driver_rank_warning_body', [
                'rank' => (string) $current->localized('name'), 'days' => $grace,
            ], ['type' => 'driver_rank'], true, ['priority' => 10]);
        } elseif ($state->downgrade_due_at->isPast()) {
            $state->fill(['rank_id' => $target->id, 'falling_to_id' => null, 'downgrade_due_at' => null])->save();
            DriverPerformanceNote::query()->create([
                'driver_id' => $driver->id, 'kind' => 'rank_change',
                'title' => __('rides.ranks.changed', ['from' => $current->localized('name'), 'to' => $target->localized('name')]),
                'body' => collect($current->missing($metrics))->map(fn ($m) => __('rides.ranks.metrics.'.$m['metric']))->implode('، '),
            ]);
            $this->center->send($driver, 'driver.rank', 'driver_rank_down_title', 'driver_rank_down_body', ['rank' => (string) $target->localized('name')], ['type' => 'driver_rank']);
        } else {
            $state->save();
        }
        DriverRideProfile::query()->updateOrCreate(['driver_id' => $driver->id], ['tier' => $state->refresh()->rank?->slug ?? 'new', 'updated_at' => now()]);

        return $state;
    }

    /** D-254: has the captain reached this rank (by its slug)? */
    public function reaches(Driver $driver, string $slug): bool
    {
        $need = DriverRank::query()->where('slug', $slug)->value('sort_order');
        if ($need === null) {
            return true;
        }
        $have = DriverRankState::query()->whereKey($driver->id)->first()?->rank?->sort_order;

        return $have !== null && $have >= (int) $need;
    }

    /** D-259: the captain disagrees with a note / warning / rank change — once, while the policy allows. */
    public function appeal(Driver $driver, DriverPerformanceNote $note, string $text): DriverPerformanceNote
    {
        if ($note->driver_id !== $driver->id) {
            throw new RidesException('performance_note_not_found', 404);
        }
        if (! $this->policy($driver)->bool('ranks.appeal_allowed') || $note->kind === 'praise') {
            throw new RidesException('performance_appeal_not_allowed', 409);
        }
        $window = $this->policy($driver)->int('ranks.appeal_window_days') ?: 14;
        if ($note->appeal_status !== 'none' || $note->created_at->lt(now()->subDays($window))) {
            throw new RidesException('performance_appeal_not_allowed', 409);
        }
        $note->forceFill(['appeal_status' => 'pending', 'appeal_text' => mb_substr(trim($text), 0, 1000), 'appealed_at' => now()])->save();

        return $note;
    }

    /** The team's answer: accepted takes the warning off the numbers (and an unfair fall back). */
    public function answer(DriverPerformanceNote $note, bool $accept, string $answer): DriverPerformanceNote
    {
        if ($note->appeal_status !== 'pending') {
            throw new RidesException('performance_appeal_not_pending', 409);
        }
        $note->forceFill(['appeal_status' => $accept ? 'accepted' : 'rejected', 'appeal_answer' => $answer, 'answered_at' => now()])->save();
        $driver = Driver::query()->findOrFail($note->driver_id);
        if ($accept && $note->kind === 'rank_change') {
            // Back to the rank before, with a fresh grace.
            DriverRankState::query()->whereKey($driver->id)->update(['downgrade_due_at' => null, 'falling_to_id' => null]);
            $previous = DriverRank::query()->where('status', true)->where('sort_order', '>', (int) DriverRankState::query()->whereKey($driver->id)->first()?->rank?->sort_order)->orderBy('sort_order')->first();
            if ($previous !== null) {
                DriverRankState::query()->whereKey($driver->id)->update(['rank_id' => $previous->id]);
            }
        } else {
            $this->evaluate($driver);
        }
        $this->center->send($driver, 'driver.appeal', $accept ? 'driver_appeal_accepted_title' : 'driver_appeal_rejected_title', 'driver_appeal_answer_body', [
            'title' => $note->title, 'answer' => $answer,
        ], ['type' => 'driver_rank']);

        return $note->refresh();
    }

    public function note(Driver $driver, string $kind, string $title, ?string $body, ?int $adminId, ?int $rideId = null): DriverPerformanceNote
    {
        $note = DriverPerformanceNote::query()->create(['driver_id' => $driver->id, 'kind' => $kind, 'title' => $title, 'body' => $body, 'admin_id' => $adminId, 'ride_id' => $rideId]);
        $this->center->send($driver, 'driver.note', 'driver_note_'.$kind.'_title', 'driver_note_body', ['title' => $title], ['type' => 'driver_rank'], $kind !== 'note');
        if ($kind === 'warning') {
            $this->evaluate($driver);
        }

        return $note;
    }

    private function policy(Driver $driver): \Modules\Rides\Services\Policy\RidePolicyData
    {
        return $this->policies->for((int) ($driver->country_id ?? 0) ?: (int) \App\Models\Country::query()->value('id'));
    }
}
