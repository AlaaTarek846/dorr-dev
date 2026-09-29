<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\UserPhoneHistory;
use Modules\Wallet\Models\WalletBeneficiary;
use Modules\Wallet\Support\MaskedName;
use Modules\Wallet\Support\WalletNumber;

/**
 * One quick-pick entry: enough to show and to re-transfer to (a wallet number, addressed the same way
 * typing it would be) — never the recipient's full phone or user id.
 */
class WalletBeneficiaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $beneficiary = $this->beneficiary;
        $wallet = $beneficiary?->wallets()->where('country_id', $this->country_id)->first();

        return [
            'name_masked' => MaskedName::of($beneficiary?->name),
            'wallet_number' => $wallet !== null ? WalletNumber::format($wallet->wallet_number) : null,
            'last_used_at' => $this->last_used_at?->toISOString(),
            'first_added_at' => $this->first_added_at?->toISOString(),
            'number_recently_changed' => $beneficiary !== null && UserPhoneHistory::query()
                ->where('user_id', $beneficiary->id)
                ->where('changed_at', '>=', now()->subDays(WalletBeneficiary::PHONE_CHANGE_WARNING_DAYS))
                ->exists(),
        ];
    }
}
