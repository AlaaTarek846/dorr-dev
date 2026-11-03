<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use App\Services\General\LoginLocation;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdComment;
use Modules\WatchEarn\Models\AdReaction;
use Modules\WatchEarn\Models\AdReport;
use Modules\WatchEarn\Models\AdView;
use Modules\WatchEarn\Models\Reward;
use Modules\WatchEarn\Models\WeSetting;

/**
 * The viewer's side (WE-030..WE-035): which ads they may see, the viewing session the server times, the
 * interaction (a comment or a reaction, D3) and the money each step moves. One view and one interaction reward per
 * viewer and ad, ever (REQ-005); every charge is atomic and only ever from the ad's reserved budget.
 */
class ViewService
{
    /** A second of slack for the network between the end of the ad on the phone and the request. */
    private const SLACK_SECONDS = 1;

    public function __construct(
        private readonly AdWalletService $adWallets,
        private readonly AdService $ads,
        private readonly RewardService $rewards,
        private readonly WatchEarnNotifier $notify,
        private readonly FinancialLedgerService $ledger,
        private readonly LoginLocation $location,
    ) {}

    /**
     * WE-030 — the ads this viewer may watch now: the ones aimed at their city first (D7), then the ones for the
     * whole country. Opening it also refreshes where they are when their IP changed (the one catch-up request).
     *
     * @return Collection<int, Ad>
     */
    public function feed(User $user, Country $country, int $limit = 20): Collection
    {
        $this->location->remember($user);

        return $this->eligible($user, $country)
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM we_ad_cities c WHERE c.ad_id = we_ads.id) THEN 0 ELSE 1 END')
            ->orderByDesc('reviewed_at')
            ->limit($limit)
            ->get();
    }

    /** WE-031 — the session starts; the server's clock times it from here. */
    public function start(User $user, Country $country, Ad $ad, ?string $platform = null): AdView
    {
        if (! $this->eligible($user, $country)->whereKey($ad->id)->exists()) {
            throw new WatchEarnException('not_eligible', 403);
        }

        return DB::transaction(function () use ($user, $ad, $platform) {
            $view = AdView::query()->lockForUpdate()->where('ad_id', $ad->id)->where('user_id', $user->id)->first();
            if ($view?->completed_at !== null) {
                throw new WatchEarnException('already_watched', 409);
            }

            $fields = [
                'media_version' => $ad->media_version,
                'city_id' => $user->geo_city_id,
                'gender' => $user->gender?->value ?? $user->gender,
                'platform' => $platform,
                'started_at' => now(),
            ];

            if ($view === null) {
                $view = AdView::query()->create(['uuid' => (string) Str::uuid(), 'ad_id' => $ad->id, 'user_id' => $user->id] + $fields);
                Ad::query()->whereKey($ad->id)->increment('impressions_count');
            } else {
                $view->forceFill($fields)->save();
            }

            return $view;
        });
    }

    /**
     * WE-031 — the ad played to the end: the server checks it took at least as long as the ad, then charges the
     * price from the ad's budget, books the platform's share, queues the view reward and holds the interaction share
     * for the window. Repeating it changes nothing.
     */
    public function complete(User $user, AdView $view): AdView
    {
        return DB::transaction(function () use ($user, $view) {
            $view = AdView::query()->lockForUpdate()->findOrFail($view->id);
            if ($view->user_id !== $user->id) {
                throw new WatchEarnException('not_found', 404);
            }
            if ($view->completed_at !== null) {
                return $view;
            }

            $ad = Ad::query()->lockForUpdate()->findOrFail($view->ad_id);
            if (! $ad->live()) {
                $this->markExhausted($ad);
                throw new WatchEarnException('ad_unavailable', 409);
            }
            if ($view->started_at->diffInSeconds(now()) + self::SLACK_SECONDS < $ad->media_seconds) {
                throw new WatchEarnException('too_fast', 422);
            }

            $settings = WeSetting::forCountry($ad->country_id);
            $this->adWallets->spend($ad, $ad->platform_share_minor + $ad->view_reward_minor);
            $hold = $ad->interaction_reward_minor > 0 ? $this->adWallets->spend($ad, $ad->interaction_reward_minor) : null;
            $ad->completed_count++;
            $ad->save();

            $view->forceFill([
                'completed_at' => now(),
                'interaction_deadline_at' => now()->addMinutes(max(1, $settings->interaction_window_minutes)),
                'charged_minor' => $ad->price_per_view_minor,
                'interaction_hold' => $hold,
                'interaction_settled_at' => $hold === null ? now() : null,
            ])->save();

            $this->platformIncome($ad, $ad->platform_share_minor, 'view');
            if ($ad->view_reward_minor > 0) {
                $this->rewards->queue($view, $ad, Reward::VIEW, $ad->view_reward_minor, $settings->view_reward_wait_hours);
            }

            $this->afterSpend($ad, $settings);

            return $view;
        });
    }

    /** WE-032 — a comment: anyone who watched it to the end; the first interaction in the window earns the reward. */
    public function comment(User $user, Ad $ad, string $body): AdComment
    {
        return DB::transaction(function () use ($user, $ad, $body) {
            $view = $this->watched($user, $ad);
            $comment = AdComment::query()->create(['ad_id' => $ad->id, 'user_id' => $user->id, 'body' => $body]);
            Ad::query()->whereKey($ad->id)->increment('comments_count');
            $this->interacted($view, 'comment');

            $this->notify->toViewers($ad, 'watch_earn.comment', ['ad_id' => $ad->uuid, 'comment' => self::commentPayload($comment->setRelation('user', $user))]);

            return $comment;
        });
    }

    /** WE-032 — one reaction per viewer (changing it earns nothing more). */
    public function react(User $user, Ad $ad, string $emoji): AdReaction
    {
        return DB::transaction(function () use ($user, $ad, $emoji) {
            $view = $this->watched($user, $ad);
            $reaction = AdReaction::query()->where('ad_id', $ad->id)->where('user_id', $user->id)->first();
            if ($reaction === null) {
                $reaction = AdReaction::query()->create(['ad_id' => $ad->id, 'user_id' => $user->id, 'emoji' => $emoji]);
                Ad::query()->whereKey($ad->id)->increment('reactions_count');
            } else {
                $reaction->update(['emoji' => $emoji]);
            }
            $this->interacted($view, 'reaction');

            $this->notify->toViewers($ad, 'watch_earn.reactions', ['ad_id' => $ad->uuid, 'reactions' => $this->reactionCounts($ad)]);

            return $reaction;
        });
    }

    public function unreact(User $user, Ad $ad): void
    {
        DB::transaction(function () use ($user, $ad) {
            if (AdReaction::query()->where('ad_id', $ad->id)->where('user_id', $user->id)->delete() > 0) {
                Ad::query()->whereKey($ad->id)->where('reactions_count', '>', 0)->decrement('reactions_count');
                $this->notify->toViewers($ad, 'watch_earn.reactions', ['ad_id' => $ad->uuid, 'reactions' => $this->reactionCounts($ad)]);
            }
        });
    }

    public function deleteComment(User $user, Ad $ad, AdComment $comment): void
    {
        if ($comment->ad_id !== $ad->id || $comment->user_id !== $user->id) {
            throw new WatchEarnException('not_found', 404);
        }

        DB::transaction(function () use ($ad, $comment) {
            $comment->delete();
            Ad::query()->whereKey($ad->id)->where('comments_count', '>', 0)->decrement('comments_count');
            $this->notify->toViewers($ad, 'watch_earn.comment_deleted', ['ad_id' => $ad->uuid, 'comment_id' => $comment->id]);
        });
    }

    public function comments(Ad $ad, int $perPage = 30): CursorPaginator
    {
        return AdComment::query()->where('ad_id', $ad->id)->with('user')->orderByDesc('id')->cursorPaginate($perPage);
    }

    /** @return array<string, int> emoji => count */
    public function reactionCounts(Ad $ad): array
    {
        return AdReaction::query()->where('ad_id', $ad->id)->selectRaw('emoji, COUNT(*) n')->groupBy('emoji')->orderByDesc('n')
            ->pluck('n', 'emoji')->map(fn ($n) => (int) $n)->all();
    }

    /** WE-035 — one report per viewer; enough unique ones suspend the ad and send it to the review. */
    public function report(User $user, Ad $ad, string $reason, ?string $note = null): void
    {
        if ($ad->user_id === $user->id) {
            throw new WatchEarnException('not_eligible', 403);
        }

        DB::transaction(function () use ($user, $ad, $reason, $note) {
            if (AdReport::query()->where('ad_id', $ad->id)->where('user_id', $user->id)->exists()) {
                throw new WatchEarnException('already_reported', 409);
            }
            AdReport::query()->create(['ad_id' => $ad->id, 'user_id' => $user->id, 'reason' => $reason, 'note' => $note]);
            Ad::query()->whereKey($ad->id)->increment('reports_count');

            $threshold = WeSetting::forCountry($ad->country_id)->report_threshold;
            $fresh = $ad->fresh();
            if ($threshold > 0 && $fresh->reports_count >= $threshold && in_array($fresh->status, [AdStatus::Active, AdStatus::Paused, AdStatus::Exhausted], true)) {
                $this->ads->suspend($fresh, 'reports');
            }
        });
    }

    /**
     * The scheduler: interaction windows that closed without an interaction. The held share goes to the platform,
     * or back into the ad's budget (`unclaimed_interaction`, D8).
     */
    public function settleExpired(): int
    {
        $n = 0;
        AdView::query()->whereNotNull('completed_at')->whereNull('interaction_settled_at')->where('interaction_deadline_at', '<=', now())
            ->orderBy('id')->limit(1000)->pluck('id')
            ->each(function (int $id) use (&$n) {
                DB::transaction(function () use ($id, &$n) {
                    $view = AdView::query()->lockForUpdate()->find($id);
                    if ($view === null || $view->interaction_settled_at !== null || $view->interacted_at !== null) {
                        return;
                    }
                    $ad = Ad::query()->lockForUpdate()->findOrFail($view->ad_id);
                    $hold = (array) $view->interaction_hold;
                    $amount = array_sum($hold);

                    if ($amount > 0 && $ad->unclaimed_interaction === WeSetting::UNCLAIMED_NOT_CHARGED) {
                        $this->adWallets->unspend($ad, $hold);
                        if ($ad->status === AdStatus::Exhausted && $ad->reservedMinor() >= $ad->price_per_view_minor) {
                            $ad->status = AdStatus::Active;
                        }
                        $ad->save();
                    } elseif ($amount > 0) {
                        $this->platformIncome($ad, $amount, 'interaction');
                    }

                    $view->forceFill(['interaction_settled_at' => now()])->save();
                    $n++;
                });
            });

        return $n;
    }

    /** @return array<string, mixed> */
    public static function commentPayload(AdComment $comment): array
    {
        $user = $comment->user;

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toISOString(),
            'user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => method_exists($user, 'getFirstMediaUrl') ? ($user->getFirstMediaUrl('avatar') ?: null) : null,
            ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    /** Live ads of this country the viewer is in the audience of and hasn't watched to the end. */
    private function eligible(User $user, Country $country): Builder
    {
        $gender = $user->gender?->value ?? $user->gender;
        $locale = app()->getLocale();
        $cityId = $user->geo_city_id;

        return Ad::query()
            ->where('country_id', $country->id)
            ->where('status', AdStatus::Active->value)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereRaw('(reserved_reward_minor + reserved_spend_only_minor + reserved_withdrawable_minor) >= price_per_view_minor')
            ->where('user_id', '!=', $user->id)
            ->where(fn (Builder $q) => $q->whereNull('target_gender')->when($gender, fn (Builder $q) => $q->orWhere('target_gender', $gender)))
            ->where(fn (Builder $q) => $q->whereNull('target_locale')->orWhere('target_locale', $locale))
            ->where(fn (Builder $q) => $q->whereDoesntHave('cities')->when($cityId, fn (Builder $q) => $q->orWhereHas('cities', fn (Builder $c) => $c->where('city_id', $cityId))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('we_views')->whereColumn('we_views.ad_id', 'we_ads.id')
                ->where('we_views.user_id', $user->id)->whereNotNull('we_views.completed_at'));
    }

    private function watched(User $user, Ad $ad): AdView
    {
        $view = AdView::query()->lockForUpdate()->where('ad_id', $ad->id)->where('user_id', $user->id)->whereNotNull('completed_at')->first();
        if ($view === null) {
            throw new WatchEarnException('watch_first', 403);
        }

        return $view;
    }

    /** The first interaction inside the window turns the held share into the viewer's reward. */
    private function interacted(AdView $view, string $type): void
    {
        if (! $view->canInteract()) {
            return;
        }

        $ad = Ad::query()->lockForUpdate()->findOrFail($view->ad_id);
        $amount = array_sum((array) $view->interaction_hold);
        $view->forceFill(['interacted_at' => now(), 'interaction_type' => $type, 'interaction_settled_at' => now()])->save();
        $ad->increment('interactions_count');

        if ($amount > 0) {
            $this->rewards->queue($view, $ad, Reward::INTERACTION, $amount, WeSetting::forCountry($ad->country_id)->interaction_reward_wait_hours);
        }
        $this->notify->stats($ad->refresh());
    }

    /** After a charge: out of budget → exhausted; under the warning line → one push (WE-015). */
    private function afterSpend(Ad $ad, WeSetting $settings): void
    {
        if ($ad->reservedMinor() < $ad->price_per_view_minor) {
            $this->markExhausted($ad);

            return;
        }

        if ($ad->low_balance_notified_at === null && $ad->budget_minor > 0
            && $ad->reservedMinor() * 100 <= $ad->budget_minor * $settings->low_balance_percent) {
            $ad->forceFill(['low_balance_notified_at' => now()])->save();
            $this->notify->advertiser($ad, 'low_balance', ['views' => $ad->viewsLeft()]);

            return;
        }

        $this->notify->stats($ad);
    }

    private function markExhausted(Ad $ad): void
    {
        if ($ad->status === AdStatus::Active && $ad->reservedMinor() < $ad->price_per_view_minor) {
            $ad->forceFill(['status' => AdStatus::Exhausted])->save();
            $this->notify->advertiser($ad, 'exhausted');
        }
    }

    private function platformIncome(Ad $ad, int $amountMinor, string $part): void
    {
        if ($amountMinor <= 0) {
            return;
        }

        $country = $ad->country()->with('currency')->first();
        $this->ledger->record(
            'watch_earn_revenue', FinancialEntryType::Income, $amountMinor, $country->currency, $country,
            reference: $ad, notes: ['key' => 'watch_earn.ledger.'.$part, 'variables' => ['title' => $ad->title]],
        );
    }
}
