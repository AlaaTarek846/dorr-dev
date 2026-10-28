<?php

namespace Modules\Rides\Services\Ai;

use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\User\Models\User;

/**
 * AI-09 — the category a rider is likely to want for this trip, worked out only from the rider's own
 * bookings (which category for a trip of this length and with this many people). Nothing about who the
 * rider is — no age, gender, health or anything sensitive — is guessed or used.
 *
 * A DORR AI tool, so only on a DORR AI plan (ADR-013): without one the answer says so and the app shows
 * nothing. Read-only: a suggestion never picks for the rider.
 */
class RiderSuggestions
{
    private const LOOK_BACK_DAYS = 180;

    private const MIN_SIMILAR = 3;

    public function __construct(private readonly RideAi $ai) {}

    /**
     * @param  list<string>|null  $offered  the categories priced for this trip — the suggestion is one of them
     * @return array{allowed: bool, reason: ?string, category: ?string, name: ?string, confidence: ?float, why: ?string}
     */
    public function for(User $rider, ?int $distanceM, ?int $passengers, ?array $offered): array
    {
        $plan = $this->ai->entitlement($rider);
        if (! $plan['allowed']) {
            return ['allowed' => false, 'reason' => $plan['reason'] ?? 'no_plan', 'category' => null, 'name' => null, 'confidence' => null, 'why' => null];
        }

        $rides = Ride::query()->where('user_id', $rider->id)->where('status', RideStatus::Completed->value)
            ->where('completed_at', '>=', now()->subDays(self::LOOK_BACK_DAYS))
            ->latest('id')->limit(100)->get(['category_id', 'planned_distance_m', 'passengers']);
        // Trips like this one: a similar length (±40%) and, when said, as many people or more.
        $similar = $rides->filter(function (Ride $r) use ($distanceM, $passengers) {
            if ($distanceM !== null && $distanceM > 0 && $r->planned_distance_m !== null) {
                $ratio = (int) $r->planned_distance_m / $distanceM;
                if ($ratio < 0.6 || $ratio > 1.4) {
                    return false;
                }
            }

            return $passengers === null || (int) ($r->passengers ?? 1) >= $passengers;
        });
        $pool = $similar->count() >= self::MIN_SIMILAR ? $similar : $rides;
        if ($pool->count() < self::MIN_SIMILAR) {
            return ['allowed' => true, 'reason' => 'not_enough_history', 'category' => null, 'name' => null, 'confidence' => null, 'why' => null];
        }

        $categories = RideCategory::query()->whereIn('id', $pool->pluck('category_id')->unique())->with('translations')->get()->keyBy('id');
        $counts = $pool->countBy('category_id')->sortDesc();
        foreach ($counts as $categoryId => $times) {
            $category = $categories->get($categoryId);
            if ($category === null || ($offered !== null && ! in_array($category->slug, $offered, true))) {
                continue;
            }
            $confidence = round($times / $pool->count(), 2);

            return [
                'allowed' => true, 'reason' => null,
                'category' => $category->slug, 'name' => $category->translated('name'), 'confidence' => $confidence,
                'why' => __('rides.ai.suggest_why', ['category' => $category->translated('name'), 'times' => $times, 'of' => $pool->count()]),
            ];
        }

        return ['allowed' => true, 'reason' => 'not_offered', 'category' => null, 'name' => null, 'confidence' => null, 'why' => null];
    }
}
