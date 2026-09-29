<?php

namespace Modules\Wallet\Concerns;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Support\OwnerType;

/**
 * Added to User and Provider only (docs/wallet-structure.md §15's "Morph
 * لـ User و Provider") — deliberately no financial logic in here, that's
 * entirely Modules\Wallet\Services\WalletService's job.
 *
 * Returns a plain query Builder, not an Eloquent relation: owner_type stores
 * the OwnerType alias, not a class name, so Eloquent's own morphMany() can't
 * match it without registering a global Relation::morphMap() — which is
 * exactly what OwnerType exists to avoid (see its docblock).
 */
trait HasWallets
{
    public function wallets(): Builder
    {
        return Wallet::query()
            ->where('owner_type', OwnerType::aliasFor($this))
            ->where('owner_id', $this->getKey());
    }

    public function walletFor(Country $country): ?Wallet
    {
        return $this->wallets()->where('country_id', $country->id)->first();
    }
}
