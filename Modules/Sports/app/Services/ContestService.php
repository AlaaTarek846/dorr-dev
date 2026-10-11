<?php

namespace Modules\Sports\Services;

use App\Models\Country;
use App\Models\NotificationDevice;
use App\Support\LocaleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsContest;
use Modules\Sports\Models\SportsContestWinner;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsPrediction;
use Modules\Sports\Models\SportsSetting;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\CouponService;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;
use Throwable;

/**
 * Prediction contests with prizes (docs/sports-plan.md §5.2, decision 2026-10-06):
 *  - only in countries the admin opened prizes in, after a legal review (closed by default);
 *  - free to enter; scored from official results only;
 *  - winners by the contest's rule (exact score · right winner · most points), shared each / split
 *    / first N, within the budget, by a fair recorded draw when there are more than it allows;
 *  - only verified phones, accounts older than N days, one winner per device;
 *  - prizes: wallet credit that can't be withdrawn (spend_only, booked as `prize_cost`), a coupon
 *    for the payment screen, or a badge; above the review amount (or without auto-pay) they wait
 *    for the admin.
 */
class ContestService
{
    public function __construct(
        private readonly PredictionService $predictions,
        private readonly ChatPushNotifier $push,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /** Prizes may be given in this country (the admin opened it). */
    public function prizesIn(?int $countryId): bool
    {
        $open = SportsSetting::current()->prizes_countries;

        return $countryId !== null && ! empty($open) && in_array($countryId, array_map('intval', $open), true);
    }

    /**
     * Settle a contest once its matches are over: pick the winners and hand out the prizes.
     *
     * @return Collection<int, SportsContestWinner>
     */
    public function settle(SportsContest $contest): Collection
    {
        if ($contest->status !== 'open') {
            throw new SportsException('contest_not_open', 422);
        }
        $matches = $contest->matchesQuery()->get(['id', 'status']);
        if ($matches->isEmpty() || $matches->contains(fn ($m) => ! in_array($m->status, ['finished', 'cancelled', 'postponed'], true))) {
            throw new SportsException('contest_not_over', 422);
        }
        foreach ($matches as $m) {
            $this->predictions->settle(SportsMatch::query()->find($m->id));
        }

        $winners = DB::transaction(function () use ($contest, $matches) {
            $contest->seed ??= Str::random(16);
            $candidates = $this->candidates($contest, $matches->pluck('id')->all());
            $chosen = $this->choose($contest, $candidates);
            $rows = collect();
            foreach ($chosen as $rank => $c) {
                $rows->push(SportsContestWinner::query()->create([
                    'contest_id' => $contest->id, 'owner_type' => $c['owner_type'], 'owner_id' => $c['owner_id'], 'country_id' => $c['country_id'],
                    'points' => $c['score'], 'rank' => $rank + 1, 'prize_type' => $contest->prize_type, 'amount_minor' => $c['amount'],
                    'currency_code' => $c['currency'], 'status' => 'pending',
                ]));
            }
            $contest->forceFill(['status' => 'settled', 'settled_at' => now()])->save();

            return $rows;
        });

        foreach ($winners as $w) {
            $review = ! $contest->auto_pay || ($contest->review_above_minor !== null && (int) $w->amount_minor > $contest->review_above_minor);
            $review ? $w->update(['status' => 'review']) : $this->pay($w);
        }

        return $winners->map->refresh();
    }

    /**
     * Everyone with predictions on the contest's matches, scored by its rule, who may win.
     *
     * @param  list<int>  $matchIds
     * @return Collection<int, array<string, mixed>>
     */
    private function candidates(SportsContest $contest, array $matchIds): Collection
    {
        $rows = SportsPrediction::query()->whereIn('match_id', $matchIds)->where('result', '!=', 'void')
            ->when($contest->starts_at, fn ($q) => $q->where('created_at', '>=', $contest->starts_at))
            ->get()->groupBy(fn ($p) => $p->owner_type.':'.$p->owner_id);

        $out = collect();
        foreach ($rows as $key => $preds) {
            $score = match ($contest->rule) {
                'exact' => $preds->where('exact', true)->count(),
                'winner' => $preds->where('correct_winner', true)->count(),
                default => (int) $preds->sum('points'),
            };
            if ($score <= 0) {
                continue;
            }
            [$type, $id] = explode(':', $key);
            $account = rescue(fn () => ParticipantType::modelClassFor($type)::query()->find($id), null, false);
            if ($account === null || $account->phone_verified_at === null) {
                continue;
            }
            if ($account->created_at !== null && $account->created_at->gt(now()->subDays($contest->min_account_days))) {
                continue;
            }
            $countryId = (int) ($account->country_id ?? 0) ?: null;
            if (! $contest->runsIn($countryId)) {
                continue;
            }
            // Money and coupons only where prizes are open (legal review per country).
            if ($contest->prize_type !== 'badge' && ! $this->prizesIn($countryId)) {
                continue;
            }
            $out->push([
                'owner_type' => $type, 'owner_id' => (int) $id, 'account' => $account, 'country_id' => $countryId, 'score' => $score,
                'first_at' => $preds->min('created_at'),
            ]);
        }

        return $out;
    }

    /**
     * Winners and their prizes: top score (each / split), or the first N by score then time;
     * capped by max_winners and the budget with a fair draw seeded by the contest; one per device.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function choose(SportsContest $contest, Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }
        if ($contest->distribution === 'first_n') {
            $ordered = $candidates->sortBy([['score', 'desc'], ['first_at', 'asc']])->values();
        } else {
            $top = $candidates->max('score');
            $ordered = $this->draw($candidates->where('score', $top)->values(), (string) $contest->seed);
        }

        // One winner per phone: the wallet's device ids (the same phone across accounts) and push ids.
        $ownerIds = $ordered->pluck('owner_id');
        $trusted = DB::table('wallet_trusted_devices')->whereIn('owner_id', $ownerIds)->get(['owner_type', 'owner_id', 'device_id'])
            ->groupBy(fn ($d) => $d->owner_type.':'.$d->owner_id);
        $pushes = NotificationDevice::query()->whereIn('owner_id', $ownerIds)->get(['owner_id', 'player_id'])->groupBy('owner_id');
        $seen = [];
        $ordered = $ordered->filter(function ($c) use ($trusted, $pushes, &$seen) {
            $ids = array_merge(
                $trusted->get($c['owner_type'].':'.$c['owner_id'], collect())->pluck('device_id')->map(fn ($d) => 'device:'.$d)->all(),
                $pushes->get($c['owner_id'], collect())->pluck('player_id')->filter()->map(fn ($d) => 'push:'.$d)->all(),
            );
            if (array_intersect($ids, $seen) !== []) {
                return false;
            }
            $seen = array_merge($seen, $ids);

            return true;
        })->values();

        if ($contest->max_winners !== null) {
            $ordered = $ordered->take($contest->max_winners);
        }

        $chosen = [];
        $spent = [];
        $byCountry = $ordered->groupBy('country_id');
        foreach ($ordered as $c) {
            $country = $c['country_id'] ? Country::query()->with('currency')->find($c['country_id']) : null;
            $amount = null;
            if (in_array($contest->prize_type, ['wallet', 'coupon'], true)) {
                $amount = (int) (($contest->prize_amounts ?? [])[(string) $c['country_id']] ?? 0);
                if ($contest->prize_type === 'coupon' && data_get($contest->coupon, 'kind') === 'percent') {
                    $amount = (int) data_get($contest->coupon, 'value', 0);
                } elseif ($contest->distribution === 'split') {
                    $amount = intdiv($amount, max(1, $byCountry->get($c['country_id'])->count()));
                }
                if ($amount <= 0) {
                    continue;
                }
                $budget = $contest->budget_minor;
                $key = (string) $c['country_id'];
                if ($budget !== null && $contest->prize_type === 'wallet' && ($spent[$key] ?? 0) + $amount > $budget) {
                    continue;
                }
                $spent[$key] = ($spent[$key] ?? 0) + $amount;
            }
            $chosen[] = $c + ['amount' => $amount, 'currency' => $country?->currency?->code];
        }

        return $chosen;
    }

    /**
     * A fair, repeatable shuffle (the seed is stored on the contest, so the draw can be checked).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function draw(Collection $items, string $seed): Collection
    {
        return $items->sortBy(fn ($c) => hash('sha256', $seed.'|'.$c['owner_type'].':'.$c['owner_id']))->values();
    }

    /** Hand a prize over (once) and tell the winner. */
    public function pay(SportsContestWinner $w): SportsContestWinner
    {
        if ($w->status === 'paid') {
            return $w;
        }
        $contest = $w->contest;
        $account = ParticipantType::modelClassFor($w->owner_type)::query()->findOrFail($w->owner_id);
        $country = $w->country_id ? Country::query()->with('currency')->find($w->country_id) : null;
        $title = $contest->translated('name') ?: (string) __('sports.contest.default_name');

        try {
            DB::transaction(function () use ($w, $contest, $account, $country, $title) {
                if ($w->prize_type === 'wallet') {
                    if ($country === null || (int) $w->amount_minor <= 0) {
                        throw new SportsException('prize_unpayable', 422);
                    }
                    $wallets = app(WalletService::class);
                    $wallet = $wallets->firstOrCreateWallet($account, $country);
                    $tx = $wallets->credit($wallet, (int) $w->amount_minor, WalletBucket::SpendOnly, WalletTransactionType::Reward, [
                        'idempotency_key' => "contest:{$contest->uuid}:{$w->owner_type}:{$w->owner_id}",
                        'reference_type' => 'sports_contest',
                        'reference_id' => $contest->id,
                        'notes' => ['key' => 'wallet.notes.reward', 'variables' => ['title' => $title]],
                    ]);
                    app(FinancialLedgerService::class)->record(
                        'prize_cost', FinancialEntryType::Expense, (int) $w->amount_minor, $country->currency, $country,
                        reference: $w, notes: ['key' => 'wallet.notes.reward', 'variables' => ['title' => $title]], walletTransaction: $tx,
                    );
                    $w->wallet_transaction_id = $tx->id;
                } elseif ($w->prize_type === 'coupon') {
                    $c = (array) $contest->coupon;
                    $coupon = app(CouponService::class)->issue($account, $country, (string) ($c['kind'] ?? 'fixed'), (int) $w->amount_minor, [
                        'max_discount_minor' => $c['max_discount_minor'] ?? null,
                        'purposes' => $c['purposes'] ?? null,
                        'expires_at' => now()->addDays((int) ($c['valid_days'] ?? 30)),
                        'source' => 'sports_contest',
                        'source_id' => $contest->id,
                    ]);
                    $w->coupon_id = $coupon->id;
                }
                $w->status = 'paid';
                $w->paid_at = now();
                $w->save();
            });
        } catch (Throwable $e) {
            Log::warning('[sports] prize '.$w->id.': '.$e->getMessage());
            $w->update(['status' => 'review', 'note' => mb_substr($e->getMessage(), 0, 300)]);

            return $w->refresh();
        }

        $this->tell($w->refresh(), $title);

        return $w;
    }

    private function tell(SportsContestWinner $w, string $title): void
    {
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = '🏆 '.__('sports.contest.won_title', [], $locale);
            $contents[$locale] = (string) __('sports.contest.won_'.$w->prize_type, ['title' => $title], $locale);
        }
        $this->push->toAccounts([[$w->owner_type, (int) $w->owner_id]], $headings, $contents, ['type' => 'sports', 'event' => 'sports.prize', 'contest_id' => $w->contest->uuid]);
        $this->broadcaster->toAccounts([[$w->owner_type, (int) $w->owner_id]], 'sports.prize', [
            'contest_id' => $w->contest->uuid, 'prize_type' => $w->prize_type, 'amount_minor' => $w->amount_minor, 'currency_code' => $w->currency_code, 'title' => $title,
        ]);
    }

    /** Contests whose matches are all over (the `sports:contests` command). */
    public function settleDue(): int
    {
        $n = 0;
        SportsContest::query()->open()->get()->each(function (SportsContest $c) use (&$n) {
            try {
                $this->settle($c);
                $n++;
            } catch (SportsException) {
                // not over yet
            }
        });

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SportsContest $c, ?Model $me = null, ?int $countryId = null): array
    {
        $c->loadMissing(['translations', 'match.home.translations', 'match.away.translations', 'competition.translations']);
        $amount = $countryId !== null ? (($c->prize_amounts ?? [])[(string) $countryId] ?? null) : null;
        $currency = $countryId !== null ? Country::query()->with('currency')->find($countryId)?->currency?->code : null;
        $mine = $me ? SportsContestWinner::query()->where('contest_id', $c->id)->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())->first() : null;

        return [
            'id' => $c->uuid,
            'name' => $c->translated('name'),
            'terms' => $c->translated('terms'),
            'scope' => $c->scope,
            'rule' => $c->rule,
            'status' => $c->status,
            'match_id' => $c->match?->uuid,
            'match' => $c->match ? trim(($c->match->home?->displayName() ?? '').' – '.($c->match->away?->displayName() ?? '')) : null,
            'competition' => $c->competition?->displayName(),
            'round' => $c->round,
            'ends_at' => $c->ends_at?->toIso8601String(),
            'prize_type' => $c->prize_type,
            'prize_amount_minor' => $c->prize_type === 'coupon' && data_get($c->coupon, 'kind') === 'percent' ? null : $amount,
            'prize_percent' => $c->prize_type === 'coupon' && data_get($c->coupon, 'kind') === 'percent' ? (int) data_get($c->coupon, 'value') : null,
            'currency_code' => $currency,
            'distribution' => $c->distribution,
            'max_winners' => $c->max_winners,
            'prizes_here' => $c->prize_type === 'badge' || $this->prizesIn($countryId),
            'my_prize' => $mine ? ['status' => $mine->status, 'amount_minor' => $mine->amount_minor, 'currency_code' => $mine->currency_code, 'prize_type' => $mine->prize_type] : null,
        ];
    }
}
