<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Exceptions\NotEligibleException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Support\EligibilityResult;
use Modules\Wallet\Support\OwnerType;

/**
 * The one place the debt limit is decided (wallet-plan.md §14) — for a provider
 * about to receive a request and for a user about to create one, with the same
 * logic and the same per-country setting:
 *
 *   eligible  ⇔  total balance (withdrawable + spend_only)  ≥  min_allowed_balance
 *
 * `min_allowed_balance_*` is *signed*: −10000 allows a debt of 100.00, 0 means
 * "balance must not be negative". The balance is the wallet of the *service's
 * country* — a debt in one country never blocks another.
 *
 * No hardcoded fallbacks: if the country's settings row is missing the check
 * fails closed (not eligible) instead of guessing a limit.
 *
 * Call sites (once the orders module exists): at dispatch use
 * {@see self::filterEligibleProviders()} (one SQL condition, no per-provider
 * loop); at request creation/acceptance use {@see self::assertEligible()} — and
 * again at the final confirmation, inside the same lock, passing the locked
 * wallet, because the balance may have changed since the first check.
 */
class WalletEligibilityService
{
    /**
     * @param  Wallet|null  $lockedWallet  pass the already-locked wallet to check against it (no extra read)
     */
    public function check(Model $owner, Country $country, ?Wallet $lockedWallet = null): EligibilityResult
    {
        $alias = OwnerType::aliasFor($owner);
        $wallet = $lockedWallet ?? Wallet::query()
            ->where('owner_type', $alias)
            ->where('owner_id', $owner->getKey())
            ->where('country_id', $country->id)
            ->first();

        $balance = $wallet?->totalBalanceMinor() ?? 0;
        $min = $this->limitFor($alias, $country);

        if ($min === null) {
            return new EligibilityResult(false, $balance, null, 0, EligibilityResult::REASON_SETTINGS_MISSING);
        }

        if ($balance >= $min) {
            return new EligibilityResult(true, $balance, $min, 0);
        }

        return new EligibilityResult(false, $balance, $min, $min - $balance, EligibilityResult::REASON_BELOW_LIMIT);
    }

    /**
     * @throws NotEligibleException
     */
    public function assertEligible(Model $owner, Country $country, ?Wallet $lockedWallet = null): EligibilityResult
    {
        $result = $this->check($owner, $country, $lockedWallet);

        if (! $result->eligible) {
            throw new NotEligibleException($result);
        }

        return $result;
    }

    /**
     * Narrows a *provider* query to those allowed to receive requests in this
     * country — a single SQL condition on the stored balances, no loop.
     * A provider with no wallet yet has balance 0, so they qualify only when
     * the limit is ≤ 0.
     */
    public function filterEligibleProviders(Builder $providers, Country $country): Builder
    {
        $min = $this->limitFor('provider', $country);

        if ($min === null) {
            return $providers->whereRaw('1 = 0'); // fail closed
        }

        $idColumn = $providers->getModel()->getTable().'.'.$providers->getModel()->getKeyName();
        $walletsInCountry = fn () => Wallet::query()
            ->select('owner_id')
            ->where('owner_type', 'provider')
            ->where('country_id', $country->id);

        return $providers->where(function (Builder $q) use ($idColumn, $walletsInCountry, $min) {
            $q->whereIn($idColumn, $walletsInCountry()->whereRaw('(withdrawable_minor + spend_only_minor) >= ?', [$min]));

            if ($min <= 0) {
                $q->orWhereNotIn($idColumn, $walletsInCountry());
            }
        });
    }

    private function limitFor(string $ownerAlias, Country $country): ?int
    {
        $settings = WalletSetting::query()->where('country_id', $country->id)->first();

        if ($settings === null) {
            return null;
        }

        return $ownerAlias === 'provider'
            ? $settings->min_allowed_balance_provider_minor
            : $settings->min_allowed_balance_user_minor;
    }
}
