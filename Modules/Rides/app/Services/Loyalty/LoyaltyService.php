<?php

namespace Modules\Rides\Services\Loyalty;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\LoyaltyAccount;
use Modules\Rides\Models\LoyaltyBadge;
use Modules\Rides\Models\LoyaltyChallenge;
use Modules\Rides\Models\LoyaltyChallengeProgress;
use Modules\Rides\Models\LoyaltyEntry;
use Modules\Rides\Models\LoyaltyRedemption;
use Modules\Rides\Models\LoyaltyReward;
use Modules\Rides\Models\LoyaltyTier;
use Modules\Rides\Models\LoyaltyUserBadge;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\CouponService;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * DORR points for riders (docs/rides_tasks.md R-146..R-155), switched on per country (`loyalty`):
 *
 *  - Earning (R-146): every paid ride and booked service gives `loyalty.points_per_unit` points per currency
 *    unit actually paid, × the tier's multiplier, + the committed rider's bonus (R-155: no ride of theirs
 *    cancelled in the last `loyalty.committed_window_days` — a reward for good habits, never a penalty).
 *  - Tiers (R-148, R-149, R-154): by the points earned in the last `loyalty.tier_window_days`; set by the admin.
 *  - Badges (R-150) and challenges (R-151): the admin's rules, checked after each earning; points when reached.
 *  - Rewards (R-152, R-153): wallet credit (in-app money, never cash) or a coupon — the points come off in the
 *    same transaction, exactly once (an idempotency key), the stock and the per-rider limit respected.
 *
 * Every movement is one row of `loyalty_entries`; the account's balance is kept with it under a lock.
 */
class LoyaltyService
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
        private readonly NotificationCenter $center,
    ) {}

    public function enabled(?int $countryId, ?int $cityId = null): bool
    {
        return $countryId !== null && $this->features->allows($countryId, $cityId, 'loyalty');
    }

    // ---------------------------------------------------------------- earning

    /** R-146: a completed ride — on what the rider actually paid (not an old debt, nor a tip). */
    public function earnForRide(Ride $ride): ?int
    {
        if ($ride->status !== RideStatus::Completed || ! $this->enabled((int) $ride->country_id, $ride->city_id === null ? null : (int) $ride->city_id)) {
            return null;
        }
        $paid = max(0, (int) $ride->final_total_minor - (int) $ride->previous_balance_minor);

        return $this->earn($ride->rider()->first(), (int) $ride->country_id, $paid, (int) $ride->currency_id, 'ride', 'ride', (int) $ride->id, 'ride:'.$ride->uuid,
            ['key' => 'rides.loyalty.notes.ride', 'variables' => ['number' => $ride->number]], $ride->category?->slug);
    }

    /** A bus ticket / a boat / a driver — once it's been given. */
    public function earnForService(?User $rider, int $countryId, int $paidMinor, int $currencyId, string $type, int $id, string $what): ?int
    {
        if ($rider === null || ! $this->enabled($countryId)) {
            return null;
        }

        return $this->earn($rider, $countryId, $paidMinor, $currencyId, 'service', $type, $id, $type.':'.$id,
            ['key' => 'rides.loyalty.notes.service', 'variables' => ['what' => $what]], null);
    }

    private function earn(?User $rider, int $countryId, int $paidMinor, int $currencyId, string $kind, string $refType, int $refId, string $key, array $note, ?string $category): ?int
    {
        if ($rider === null || $paidMinor <= 0) {
            return null;
        }
        $policy = $this->policies->for($countryId);
        $places = (int) (\App\Models\Currency::query()->whereKey($currencyId)->value('decimal_places') ?? 2);
        $base = intdiv($paidMinor * max(0, $policy->int('loyalty.points_per_unit') ?: 1), 10 ** $places);
        if ($base <= 0) {
            return null;
        }
        $account = $this->account($rider);
        $multiplier = (int) ($account->tier?->multiplier_bp ?? 10000);
        $bonus = $this->isCommitted($rider, $policy->int('loyalty.committed_window_days') ?: 30) ? $policy->int('loyalty.committed_bonus_bp') : 0;
        $points = intdiv($base * ($multiplier + $bonus) + 5000, 10000);

        $entry = $this->post($rider, $points, $kind, $refType, $refId, $key, $note);
        if ($entry === null) {
            return null; // already counted
        }
        $this->afterEarning($rider, $countryId, $kind, $category, $paidMinor);

        return $points;
    }

    /** Badges, challenges and the tier, after points came in. */
    private function afterEarning(User $rider, int $countryId, string $kind, ?string $category, int $paidMinor): void
    {
        // Challenges in their dates for this country (or every country).
        LoyaltyChallenge::query()->where('status', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->where(fn ($q) => $q->whereNull('country_id')->orWhere('country_id', $countryId))->get()
            ->each(function (LoyaltyChallenge $c) use ($rider, $kind, $category, $paidMinor) {
                $step = match ($c->rule) {
                    'rides', 'clean_rides' => $kind === 'ride' ? 1 : 0,
                    'category_rides' => $kind === 'ride' && $category === $c->category ? 1 : 0,
                    'services' => $kind === 'service' ? 1 : 0,
                    'spend_minor' => $paidMinor,
                    default => 0,
                };
                if ($step > 0) {
                    $this->advance($rider, $c, $step);
                }
            });
        $this->checkBadges($rider);
        $this->refreshTier($rider);
    }

    private function advance(User $rider, LoyaltyChallenge $c, int $step): void
    {
        $done = DB::transaction(function () use ($rider, $c, $step) {
            $row = LoyaltyChallengeProgress::query()->firstOrCreate(['challenge_id' => $c->id, 'user_id' => $rider->id]);
            $row = LoyaltyChallengeProgress::query()->lockForUpdate()->findOrFail($row->id);
            if ($row->completed_at !== null || $row->failed) {
                return false;
            }
            $row->progress += $step;
            if ($row->progress >= $c->target) {
                $row->completed_at = now();
            }
            $row->save();

            return $row->completed_at !== null;
        });
        if ($done) {
            $this->post($rider, $c->reward_points, 'challenge', 'loyalty_challenge', $c->id, 'challenge:'.$c->id.':'.$rider->id,
                ['key' => 'rides.loyalty.notes.challenge', 'variables' => ['title' => $c->localized('title')]]);
            $this->center->send($rider, 'loyalty.challenge', 'loyalty_challenge_title', 'loyalty_challenge_body', [
                'title' => (string) $c->localized('title'), 'points' => $c->reward_points,
            ], ['type' => 'loyalty']);
        }
    }

    /** R-155 / clean_rides: a cancelled ride of mine fails the challenges that ask for none. */
    public function riderCancelled(User $rider): void
    {
        LoyaltyChallenge::query()->where('status', true)->where('rule', 'clean_rides')->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('id')
            ->each(function (int $id) use ($rider) {
                LoyaltyChallengeProgress::query()->updateOrCreate(['challenge_id' => $id, 'user_id' => $rider->id], ['failed' => true]);
            });
    }

    private function checkBadges(User $rider): void
    {
        $have = LoyaltyUserBadge::query()->where('user_id', $rider->id)->pluck('badge_id')->all();
        LoyaltyBadge::query()->where('status', true)->whereNotIn('id', $have)->get()->each(function (LoyaltyBadge $b) use ($rider) {
            if ($this->badgeProgress($rider, $b) < $b->threshold) {
                return;
            }
            try {
                LoyaltyUserBadge::query()->create(['user_id' => $rider->id, 'badge_id' => $b->id, 'earned_at' => now()]);
            } catch (QueryException) {
                return; // earned meanwhile
            }
            if ($b->reward_points > 0) {
                $this->post($rider, $b->reward_points, 'badge', 'loyalty_badge', $b->id, 'badge:'.$b->id.':'.$rider->id,
                    ['key' => 'rides.loyalty.notes.badge', 'variables' => ['name' => $b->localized('name')]]);
            }
            $this->center->send($rider, 'loyalty.badge', 'loyalty_badge_title', 'loyalty_badge_body', ['name' => (string) $b->localized('name')], ['type' => 'loyalty']);
        });
    }

    /** How far a rider is on a badge's rule. */
    public function badgeProgress(User $rider, LoyaltyBadge $b): int
    {
        $rides = fn () => Ride::query()->where('user_id', $rider->id)->where('status', RideStatus::Completed->value);

        return match ($b->rule) {
            'rides' => $rides()->count(),
            'category_rides' => $rides()->whereHas('category', fn ($q) => $q->where('slug', $b->category))->count(),
            'services' => LoyaltyEntry::query()->where('user_id', $rider->id)->where('kind', 'service')->count(),
            'spend_minor' => (int) $rides()->sum('final_total_minor'),
            default => 0,
        };
    }

    /** R-148: the tier the points of the window reach. */
    public function refreshTier(User $rider): void
    {
        $account = $this->account($rider);
        $window = $this->policies->for((int) ($rider->country_id ?? 0) ?: (int) Country::query()->value('id'))->int('loyalty.tier_window_days') ?: 365;
        $points = $this->windowPoints($rider, $window);
        $tier = LoyaltyTier::query()->where('status', true)->where('min_points', '<=', $points)->orderByDesc('min_points')->first();
        if ($tier?->id === $account->tier_id) {
            return;
        }
        $up = $tier !== null && ($account->tier === null || $tier->min_points > $account->tier->min_points);
        $account->forceFill(['tier_id' => $tier?->id])->save();
        if ($up) {
            $this->center->send($rider, 'loyalty.tier', 'loyalty_tier_title', 'loyalty_tier_body', ['tier' => (string) $tier->localized('name')], ['type' => 'loyalty']);
        }
    }

    public function windowPoints(User $rider, int $days): int
    {
        return (int) LoyaltyEntry::query()->where('user_id', $rider->id)->where('points', '>', 0)->whereNotIn('kind', ['admin'])
            ->where('created_at', '>=', now()->subDays($days))->sum('points');
    }

    /** R-155: no ride of theirs cancelled by them in the window, and at least one ride in it. */
    public function isCommitted(User $rider, int $days): bool
    {
        $since = now()->subDays($days);
        $rides = Ride::query()->where('user_id', $rider->id)->where('requested_at', '>=', $since);
        if (! (clone $rides)->where('status', RideStatus::Completed->value)->exists()) {
            return false;
        }

        // Only a cancellation after a captain accepted counts — before that nobody was kept waiting.
        return ! (clone $rides)->where('status', RideStatus::Canceled->value)->where('cancel_actor', 'rider')->whereNotNull('accepted_at')->exists();
    }

    // ---------------------------------------------------------------- spending

    /** R-152: spend points on a reward — once per idempotency key, atomically. */
    public function redeem(User $rider, LoyaltyReward $reward, string $key): LoyaltyRedemption
    {
        if (($done = LoyaltyRedemption::query()->where('idempotency_key', 'redeem:'.$rider->id.':'.$key)->first()) !== null) {
            return $done;
        }
        if (! $reward->status || ! $this->enabled((int) $reward->country_id)) {
            throw new RidesException('loyalty_reward_unavailable', 409);
        }

        return DB::transaction(function () use ($rider, $reward, $key) {
            $reward = LoyaltyReward::query()->lockForUpdate()->findOrFail($reward->id);
            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($this->account($rider)->user_id);
            if ($reward->stock !== null && $reward->stock <= 0) {
                throw new RidesException('loyalty_reward_out_of_stock', 409);
            }
            if ($reward->per_user_limit !== null && LoyaltyRedemption::query()->where('user_id', $rider->id)->where('reward_id', $reward->id)->count() >= $reward->per_user_limit) {
                throw new RidesException('loyalty_reward_limit', 409);
            }
            if ($reward->min_tier_id !== null && ($account->tier === null || $account->tier->min_points < (int) $reward->minTier?->min_points)) {
                throw new RidesException('loyalty_reward_tier', 409, ['tier' => (string) $reward->minTier?->localized('name')]);
            }
            if ($account->balance < $reward->cost_points) {
                throw new RidesException('loyalty_not_enough_points', 422, ['points' => $reward->cost_points - $account->balance]);
            }

            $redemption = LoyaltyRedemption::query()->create([
                'user_id' => $rider->id, 'reward_id' => $reward->id, 'points' => $reward->cost_points, 'idempotency_key' => 'redeem:'.$rider->id.':'.$key,
            ]);
            $this->post($rider, -$reward->cost_points, 'redeem', 'loyalty_redemption', $redemption->id, 'redeem:'.$redemption->uuid,
                ['key' => 'rides.loyalty.notes.redeem', 'variables' => ['title' => $reward->localized('title')]]);
            $country = $reward->country()->firstOrFail();
            $title = (string) $reward->localized('title');

            if ($reward->kind === 'wallet_credit') {
                $wallets = app(WalletService::class);
                $tx = $wallets->credit($wallets->firstOrCreateWallet($rider, $country), $reward->value_minor, WalletBucket::SpendOnly, WalletTransactionType::Reward, [
                    'idempotency_key' => 'loyalty:'.$redemption->uuid, 'reference_type' => 'loyalty_redemption', 'reference_id' => $redemption->id,
                    'notes' => ['key' => 'wallet.notes.reward', 'variables' => ['title' => $title]],
                ]);
                app(FinancialLedgerService::class)->record('prize_cost', FinancialEntryType::Expense, $reward->value_minor, $country->currency, $country,
                    reference: $redemption, notes: ['key' => 'wallet.notes.reward', 'variables' => ['title' => $title]], walletTransaction: $tx);
                $result = ['credit_minor' => $reward->value_minor];
            } else {
                $percent = $reward->coupon_percent !== null && $reward->coupon_percent > 0;
                $coupon = app(CouponService::class)->issue($rider, $country, $percent ? 'percent' : 'fixed', $percent ? $reward->coupon_percent : $reward->value_minor, [
                    'max_discount_minor' => $reward->coupon_max_minor, 'expires_at' => now()->addDays(max(1, $reward->coupon_days)),
                    'source' => 'loyalty', 'source_id' => $redemption->id,
                ]);
                $result = ['coupon_code' => $coupon->code, 'expires_at' => $coupon->expires_at?->toIso8601String()];
            }
            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }
            $redemption->forceFill(['result' => $result])->save();
            $this->center->send($rider, 'loyalty.redeemed', 'loyalty_redeemed_title', 'loyalty_redeemed_body', ['title' => $title, 'points' => $reward->cost_points], ['type' => 'loyalty']);

            return $redemption->refresh();
        });
    }

    /** The admin adds / takes points (a reason is kept). */
    public function adjust(User $rider, int $points, string $reason, int $adminId): LoyaltyEntry
    {
        if ($points === 0) {
            throw new RidesException('loyalty_adjust_zero', 422);
        }
        if ($points < 0 && $this->account($rider)->balance + $points < 0) {
            throw new RidesException('loyalty_not_enough_points', 422, ['points' => -$points - $this->account($rider)->balance]);
        }

        return $this->post($rider, $points, 'admin', 'admin', $adminId, 'admin:'.$rider->id.':'.uniqid('', true), ['key' => 'rides.loyalty.notes.admin', 'variables' => ['reason' => $reason]]);
    }

    // ---------------------------------------------------------------- the ledger

    public function account(User $rider): LoyaltyAccount
    {
        return LoyaltyAccount::query()->with('tier')->firstOrCreate(['user_id' => $rider->id], ['balance' => 0, 'lifetime' => 0]);
    }

    /** One movement, once per key; the balance moves with it under a lock. Null when already posted. */
    private function post(User $rider, int $points, string $kind, string $refType, int $refId, string $key, array $note): ?LoyaltyEntry
    {
        if ($points === 0 || LoyaltyEntry::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($rider, $points, $kind, $refType, $refId, $key, $note) {
            $this->account($rider);
            $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($rider->id);
            $account->balance += $points;
            if ($points > 0 && $kind !== 'admin') {
                $account->lifetime += $points;
            }
            $account->save();
            try {
                return LoyaltyEntry::query()->create([
                    'user_id' => $rider->id, 'points' => $points, 'balance_after' => $account->balance, 'kind' => $kind,
                    'reference_type' => $refType, 'reference_id' => $refId, 'note' => $note, 'idempotency_key' => $key, 'created_at' => now(),
                ]);
            } catch (QueryException) {
                throw new RidesException('loyalty_already_posted', 409);
            }
        });
    }

    /** @return Collection<int, LoyaltyEntry> */
    public function history(User $rider, ?string $kind, int $limit = 50): Collection
    {
        return LoyaltyEntry::query()->where('user_id', $rider->id)
            ->when($kind === 'earned', fn ($q) => $q->where('points', '>', 0))
            ->when($kind === 'spent', fn ($q) => $q->where('points', '<', 0))
            ->orderByDesc('id')->limit($limit)->get();
    }
}
