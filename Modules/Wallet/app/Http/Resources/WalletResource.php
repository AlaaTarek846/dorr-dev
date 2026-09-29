<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a wallet.
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->owner();

        return [
            'id' => $this->id,
            'wallet_number' => $this->wallet_number,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'owner' => $owner === null ? null : [
                'name' => $owner->name ?? null,
                'phone' => $owner->phone ?? null,
                'email' => $owner->email ?? null,
            ],
            'country_code' => $this->country?->code,
            'currency_code' => $this->currency?->code,
            'total_minor' => $this->totalBalanceMinor(),
            'withdrawable_minor' => $this->withdrawable_minor,
            'spend_only_minor' => $this->spend_only_minor,
            'held_withdrawable_minor' => $this->held_withdrawable_minor,
            'held_spend_only_minor' => $this->held_spend_only_minor,
            'status' => (bool) $this->status,
            'last_reconciled_at' => $this->last_reconciled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
