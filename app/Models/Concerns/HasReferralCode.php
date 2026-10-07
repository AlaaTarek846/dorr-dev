<?php

namespace App\Models\Concerns;

use App\Models\ReferralCode;
use App\Support\Referral\ReferrableType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Added to User and Provider. owner_type stores the ReferrableType alias, so
 * this is a query Builder — not morphOne() — for the same reason as HasWallets.
 */
trait HasReferralCode
{
    public function referralCodes(): Builder
    {
        return ReferralCode::query()
            ->where('referrable_type', ReferrableType::aliasFor($this))
            ->where('referrable_id', $this->getKey());
    }

    public function activeReferralCode(): ?ReferralCode
    {
        return $this->referralCodes()->where('is_active', true)->latest('id')->first();
    }
}
