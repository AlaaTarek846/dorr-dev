<?php

namespace Modules\WatchEarn\Services;

use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdView;
use Modules\WatchEarn\Models\Reward;

/**
 * Viewers' rewards (WE-033, REQ-008/009): one for the view and one for the interaction, each with its own wait
 * (the country's settings), then into the wallet's reward bucket — never withdrawn, never sent on, never expiring.
 */
class RewardService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WatchEarnNotifier $notify,
    ) {}

    /** Books it (once per view and kind); with no wait it goes into the wallet at once. Inside the caller's transaction. */
    public function queue(AdView $view, Ad $ad, string $kind, int $amountMinor, int $waitHours): Reward
    {
        $reward = Reward::query()->firstOrCreate(
            ['view_id' => $view->id, 'kind' => $kind],
            [
                'user_id' => $view->user_id,
                'ad_id' => $ad->id,
                'country_id' => $ad->country_id,
                'amount_minor' => $amountMinor,
                'available_at' => now()->addHours(max(0, $waitHours)),
            ],
        );

        if ($waitHours <= 0 && $reward->released_at === null) {
            $this->release($reward, $ad);
        }

        return $reward;
    }

    /** The scheduler: rewards whose wait is over. One push per viewer per run. */
    public function releaseDue(): int
    {
        $totals = [];

        Reward::query()->whereNull('released_at')->where('available_at', '<=', now())->orderBy('id')->limit(2000)->pluck('id')
            ->each(function (int $id) use (&$totals) {
                DB::transaction(function () use ($id, &$totals) {
                    $reward = Reward::query()->lockForUpdate()->find($id);
                    if ($reward === null || $reward->released_at !== null) {
                        return;
                    }
                    $this->release($reward, $reward->ad, notify: false);
                    $totals[$reward->user_id][$reward->country_id] = ($totals[$reward->user_id][$reward->country_id] ?? 0) + $reward->amount_minor;
                });
            });

        foreach ($totals as $userId => $byCountry) {
            foreach ($byCountry as $countryId => $minor) {
                $this->notify->rewardsReleased($userId, $this->money($minor, $countryId));
            }
        }

        return array_sum(array_map('count', $totals));
    }

    private function release(Reward $reward, Ad $ad, bool $notify = true): void
    {
        $user = User::query()->find($reward->user_id);
        $country = $ad->country()->with('currency')->first();
        if ($user === null || $country === null) {
            return;
        }

        $tx = $this->wallets->credit($this->wallets->firstOrCreateWallet($user, $country), $reward->amount_minor, WalletBucket::Reward, WalletTransactionType::Reward, [
            'idempotency_key' => "we:reward:{$reward->id}",
            'reference_type' => 'we_reward',
            'reference_id' => $reward->id,
            'notes' => ['key' => 'wallet.notes.watch_reward', 'variables' => ['title' => $ad->title]],
        ]);

        $reward->forceFill(['released_at' => now(), 'wallet_transaction_id' => $tx->id])->save();

        if ($notify) {
            $this->notify->rewardsReleased($user->id, $this->money($reward->amount_minor, $country->id));
        }
    }

    private function money(int $minor, int $countryId): string
    {
        $country = \App\Models\Country::query()->with('currency')->find($countryId);
        $decimals = (int) ($country?->currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $decimals), $decimals).' '.($country?->currency?->code ?? '');
    }
}
