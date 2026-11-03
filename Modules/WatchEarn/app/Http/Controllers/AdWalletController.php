<?php

namespace Modules\WatchEarn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Support\OwnerType;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdWalletEntry;
use Modules\WatchEarn\Services\AdWalletService;

/**
 * The ad wallet (WE-011..WE-014): every amount shown by where it came from, what each ad holds, the statement, and
 * the moves in (from the wallet) and out (back to it) — each previewed before it is done (D12).
 */
class AdWalletController extends Controller
{
    public function __construct(private readonly AdWalletService $adWallets) {}

    /** GET watch-earn/ad-wallet */
    public function show(Request $request)
    {
        $user = $request->user();
        $country = currentCountry();
        $adWallet = $this->adWallets->for($user, $country);
        $locked = $this->adWallets->lockedIn($adWallet);
        $holding = Ad::query()->where('ad_wallet_id', $adWallet->id)
            ->whereIn('status', array_map(fn (AdStatus $s) => $s->value, array_filter(AdStatus::cases(), fn (AdStatus $s) => $s->holdsBudget())))
            ->orderByDesc('id')->get(['uuid', 'title', 'status', 'reserved_reward_minor', 'reserved_spend_only_minor', 'reserved_withdrawable_minor', 'budget_minor', 'spent_minor']);
        $page = AdWalletEntry::query()->where('ad_wallet_id', $adWallet->id)->with('ad:id,uuid,title')->orderByDesc('id')->cursorPaginate(30);

        return ApiResponse::success([
            'currency' => ['code' => $country->currency?->code, 'decimals' => (int) ($country->currency?->decimal_places ?? 2)],
            'free' => $this->buckets($this->adWallets->free($adWallet)),
            'locked' => $this->buckets($locked),
            'total_minor' => $adWallet->freeMinor() + array_sum($locked),
            'ads' => $holding->map(fn (Ad $ad) => [
                'id' => $ad->uuid,
                'title' => $ad->title,
                'status' => $ad->status->value,
                'locked_minor' => $ad->reservedMinor(),
                'budget_minor' => $ad->budget_minor,
                'spent_minor' => $ad->spent_minor,
            ])->all(),
            'main_wallet' => $this->mainWallet($user, $country->id),
            'entries' => collect($page->items())->map(fn (AdWalletEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'amount_minor' => $e->amount_minor,
                'by_bucket' => $this->buckets(['reward' => $e->reward_minor, 'spend_only' => $e->spend_only_minor, 'withdrawable' => $e->withdrawable_minor]),
                'free_after_minor' => $e->free_after_minor,
                'ad' => $e->ad ? ['id' => $e->ad->uuid, 'title' => $e->ad->title] : null,
                'created_at' => $e->created_at?->toISOString(),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ], __('api.retrieved'));
    }

    /** POST watch-earn/ad-wallet/fund/preview — what moving this much takes from each bucket of the wallet. */
    public function fundPreview(Request $request)
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1']]);
        $wallet = Wallet::query()->where('owner_type', OwnerType::aliasFor($request->user()))->where('owner_id', $request->user()->id)
            ->where('country_id', currentCountry()->id)->first();

        return ApiResponse::success(['take' => $this->buckets($this->adWallets->fundingSplit($wallet, (int) $data['amount_minor']))], __('api.retrieved'));
    }

    /** POST watch-earn/ad-wallet/fund — needs the wallet PIN (RequiresWalletPin). */
    public function fund(Request $request)
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $this->adWallets->fund($request->user(), currentCountry(), (int) $data['amount_minor'], $data['idempotency_key']);

        return $this->show($request);
    }

    /** POST watch-earn/ad-wallet/return/preview — what goes back to each bucket. */
    public function returnPreview(Request $request)
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1']]);
        $adWallet = $this->adWallets->for($request->user(), currentCountry());

        return ApiResponse::success(['give' => $this->buckets($this->adWallets->returnSplit($adWallet, (int) $data['amount_minor']))], __('api.retrieved'));
    }

    /** POST watch-earn/ad-wallet/return — the free balance only; what ads hold stays until they close. */
    public function return(Request $request)
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $this->adWallets->returnToWallet($request->user(), currentCountry(), (int) $data['amount_minor'], $data['idempotency_key']);

        return $this->show($request);
    }

    /** @return array{reward_minor: int, spend_only_minor: int, withdrawable_minor: int} */
    private function buckets(array $split): array
    {
        return ['reward_minor' => (int) ($split['reward'] ?? 0), 'spend_only_minor' => (int) ($split['spend_only'] ?? 0), 'withdrawable_minor' => (int) ($split['withdrawable'] ?? 0)];
    }

    private function mainWallet(User $user, int $countryId): array
    {
        $wallet = Wallet::query()->where('owner_type', OwnerType::aliasFor($user))->where('owner_id', $user->id)->where('country_id', $countryId)->first();

        return [
            'reward_minor' => max(0, $wallet?->availableMinor(WalletBucket::Reward) ?? 0),
            'spend_only_minor' => max(0, $wallet?->availableMinor(WalletBucket::SpendOnly) ?? 0),
            'withdrawable_minor' => max(0, $wallet?->availableMinor(WalletBucket::Withdrawable) ?? 0),
        ];
    }
}
