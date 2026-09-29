<?php

namespace Modules\Wallet\Observers;

use App\Models\Country;
use Modules\Wallet\Models\WalletSetting;

/**
 * Every country gets a wallet_settings row the moment it's created, with
 * conservative defaults (no debt allowed, transfers off, no min/max limits
 * — "no limit" is treated as "not configured yet", not "unlimited").
 *
 * Deliberately no default row for missing countries elsewhere in the code:
 * eligibility/limit checks must fail closed if this row is ever missing
 * (see docs/wallet-plan.md §14), not fall back to a silent hardcoded value.
 */
class WalletSettingObserver
{
    public function created(Country $country): void
    {
        WalletSetting::firstOrCreate(['country_id' => $country->id]);
    }
}
