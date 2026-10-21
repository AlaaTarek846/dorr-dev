<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\LoyaltyBadge;
use Modules\Rides\Models\LoyaltyChallenge;
use Modules\Rides\Models\LoyaltyChallengeProgress;
use Modules\Rides\Models\LoyaltyEntry;
use Modules\Rides\Models\LoyaltyRedemption;
use Modules\Rides\Models\LoyaltyReward;
use Modules\Rides\Models\LoyaltyTier;
use Modules\Rides\Models\LoyaltyUserBadge;
use Modules\Rides\Services\Loyalty\LoyaltyService;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;

/**
 * The rider's DORR points (R-146..R-155): the dashboard (balance, tier and the way to the next one, the
 * tiers side by side, badges, challenges), the history, the rewards and spending points on one.
 */
class LoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyService $loyalty,
        private readonly RidePolicies $policies,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $countryId = $rider->country_id === null ? null : (int) $rider->country_id;
        if (! $this->loyalty->enabled($countryId)) {
            return ApiResponse::success(['enabled' => false], __('api.retrieved'));
        }
        $policy = $this->policies->for($countryId);
        $account = $this->loyalty->account($rider);
        $windowDays = $policy->int('loyalty.tier_window_days') ?: 365;
        $windowPoints = $this->loyalty->windowPoints($rider, $windowDays);
        $tiers = LoyaltyTier::query()->where('status', true)->orderBy('min_points')->get();
        $current = $account->tier;
        $next = $tiers->first(fn (LoyaltyTier $t) => $t->min_points > $windowPoints);
        $from = (int) ($current?->min_points ?? 0);
        $committedDays = $policy->int('loyalty.committed_window_days') ?: 30;
        $earned = LoyaltyUserBadge::query()->where('user_id', $rider->id)->pluck('earned_at', 'badge_id');
        $progress = LoyaltyChallengeProgress::query()->where('user_id', $rider->id)->get()->keyBy('challenge_id');

        return ApiResponse::success([
            'enabled' => true,
            'balance' => (int) $account->balance,
            'lifetime' => (int) $account->lifetime,
            'points_per_unit' => $policy->int('loyalty.points_per_unit') ?: 1,
            'window_days' => $windowDays,
            'window_points' => $windowPoints,
            'tier' => $current === null ? null : $this->tier($current),
            // R-149: the way to the next tier, from the server's thresholds.
            'next_tier' => $next === null ? null : $this->tier($next) + ['points_to_go' => max(0, $next->min_points - $windowPoints)],
            'progress' => $next === null ? 1.0 : round(min(1, max(0, ($windowPoints - $from) / max(1, $next->min_points - $from))), 3),
            // R-154: every tier side by side with what it gives.
            'tiers' => $tiers->map(fn (LoyaltyTier $t) => $this->tier($t) + ['current' => $t->id === $current?->id])->values(),
            // R-155: the committed rider — a bonus for good habits.
            'committed' => [
                'active' => $this->loyalty->isCommitted($rider, $committedDays),
                'bonus_bp' => $policy->int('loyalty.committed_bonus_bp'),
                'window_days' => $committedDays,
            ],
            'badges' => LoyaltyBadge::query()->where('status', true)->orderBy('sort_order')->get()->map(fn (LoyaltyBadge $b) => [
                'id' => $b->id, 'name' => $b->localized('name'), 'description' => $b->localized('description'), 'icon' => $b->icon,
                'threshold' => $b->threshold, 'reward_points' => $b->reward_points, 'rule' => $b->rule,
                'earned_at' => isset($earned[$b->id]) ? \Illuminate\Support\Carbon::parse($earned[$b->id])->toIso8601String() : null,
                'progress' => isset($earned[$b->id]) ? $b->threshold : min($b->threshold, $this->loyalty->badgeProgress($rider, $b)),
            ])->values(),
            'challenges' => LoyaltyChallenge::query()->where('status', true)->where('ends_at', '>', now()->subDays(7))->where('starts_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId))->orderBy('ends_at')->get()
                ->map(fn (LoyaltyChallenge $c) => [
                    'id' => $c->id, 'title' => $c->localized('title'), 'description' => $c->localized('description'), 'rule' => $c->rule,
                    'target' => $c->target, 'reward_points' => $c->reward_points, 'ends_at' => $c->ends_at->toIso8601String(), 'live' => $c->isLive(),
                    'progress' => (int) ($progress->get($c->id)?->progress ?? 0), 'failed' => (bool) ($progress->get($c->id)?->failed ?? false),
                    'completed_at' => $progress->get($c->id)?->completed_at?->toIso8601String(),
                ])->values(),
            'recent' => $this->loyalty->history($rider, null, 10)->map(fn (LoyaltyEntry $e) => self::entry($e))->values(),
        ], __('api.retrieved'));
    }

    /** R-153: earned / spent, newest first. */
    public function history(Request $request): JsonResponse
    {
        $kind = $request->query('kind');

        return ApiResponse::success($this->loyalty->history($this->rider($request), in_array($kind, ['earned', 'spent'], true) ? $kind : null, 100)
            ->map(fn (LoyaltyEntry $e) => self::entry($e))->values(), __('api.retrieved'));
    }

    /** R-152: what my points buy here — each with whether I can take it now. */
    public function rewards(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        if (! $this->loyalty->enabled($rider->country_id === null ? null : (int) $rider->country_id)) {
            return ApiResponse::success([], __('api.retrieved'));
        }
        $account = $this->loyalty->account($rider);
        $taken = LoyaltyRedemption::query()->where('user_id', $rider->id)->selectRaw('reward_id, COUNT(*) as c')->groupBy('reward_id')->pluck('c', 'reward_id');
        $currency = Country::query()->find($rider->country_id)?->currency;

        return ApiResponse::success(LoyaltyReward::query()->with('minTier')->where('status', true)->where('country_id', $rider->country_id)->orderBy('sort_order')->orderBy('cost_points')->get()
            ->map(function (LoyaltyReward $r) use ($account, $taken, $currency) {
                $reason = match (true) {
                    $r->stock !== null && $r->stock <= 0 => 'out_of_stock',
                    $r->per_user_limit !== null && (int) ($taken[$r->id] ?? 0) >= $r->per_user_limit => 'limit',
                    $r->min_tier_id !== null && ($account->tier === null || $account->tier->min_points < (int) $r->minTier?->min_points) => 'tier',
                    $account->balance < $r->cost_points => 'points',
                    default => null,
                };

                return [
                    'id' => $r->id, 'title' => $r->localized('title'), 'description' => $r->localized('description'),
                    'cost_points' => $r->cost_points, 'kind' => $r->kind, 'value_minor' => $r->value_minor,
                    'coupon_percent' => $r->coupon_percent, 'coupon_days' => $r->coupon_days, 'stock' => $r->stock,
                    'min_tier' => $r->minTier?->localized('name'),
                    'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) ($currency->decimal_places ?? 2)],
                    'available' => $reason === null, 'reason' => $reason,
                ];
            })->values(), __('api.retrieved'));
    }

    public function redeem(Request $request, int $reward): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '') {
            throw new RidesException('idempotency_key_required', 422);
        }
        $model = LoyaltyReward::query()->whereKey($reward)->first() ?? throw new RidesException('loyalty_reward_unavailable', 404);
        $redemption = $this->loyalty->redeem($this->rider($request), $model, $key);

        return ApiResponse::success(self::redemption($redemption), __('rides.loyalty.redeemed'), 201);
    }

    /** R-153: what I took — the coupon codes stay here. */
    public function redemptions(Request $request): JsonResponse
    {
        return ApiResponse::success(LoyaltyRedemption::query()->with('reward')->where('user_id', $this->rider($request)->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (LoyaltyRedemption $r) => self::redemption($r))->values(), __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function tier(LoyaltyTier $t): array
    {
        return ['id' => $t->id, 'name' => $t->localized('name'), 'color' => $t->color, 'min_points' => $t->min_points, 'multiplier_bp' => $t->multiplier_bp, 'benefits' => $t->benefitList()];
    }

    /** @return array<string, mixed> */
    public static function entry(LoyaltyEntry $e): array
    {
        $note = (array) $e->note;

        return [
            'id' => $e->id, 'points' => $e->points, 'balance_after' => $e->balance_after, 'kind' => $e->kind,
            'title' => isset($note['key']) ? __($note['key'], (array) ($note['variables'] ?? [])) : null,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function redemption(LoyaltyRedemption $r): array
    {
        return [
            'uuid' => $r->uuid, 'points' => $r->points, 'title' => $r->reward?->localized('title'), 'kind' => $r->reward?->kind,
            'result' => $r->result, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
