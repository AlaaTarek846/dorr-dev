<?php

namespace Modules\Freelance\Services\Policy;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;

/**
 * The one currency of freelance work (`freelance.money`, owner 2026-11-04): every amount is in it, whatever the client's or the
 * freelancer's country, and the money lives in the international wallet — a wallet on a hidden country row whose currency is that
 * one. Everything that moves or shows freelance money asks here instead of looking at a country's currency.
 *
 * The row is created on first use (and by a migration), so a fresh database or a test needs no seeding. It is off (`status` false),
 * so it is in no country list, is never resolved for a request, and nothing that loops over the active countries meets it.
 */
class FreelanceMoney
{
    private ?Country $country = null;

    public function __construct(private readonly WalletService $wallets) {}

    /** The international wallet's country row (its currency is the freelance currency). */
    public function country(): Country
    {
        if ($this->country !== null && $this->country->exists) {
            return $this->country;
        }

        $code = (string) config('freelance.money.country_code', 'INT');
        $country = Country::query()->with('currency')->where('code', $code)->first();

        if ($country === null) {
            $country = DB::transaction(function () use ($code): Country {
                $currency = $this->ensureCurrency();
                $flag = Flag::query()->withTrashed()->firstOrCreate(['code' => (string) config('freelance.money.flag_code', 'un')], ['status' => true]);
                $country = Country::query()->withTrashed()->firstOrCreate(['code' => $code], [
                    'code_alpha3' => $code, 'dial_code' => '+0', 'is_default' => false,
                    'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => false,
                ]);
                if ($country->trashed()) {
                    $country->restore();
                }
                foreach ((array) config('freelance.money.country_name', []) as $locale => $name) {
                    $country->translations()->firstOrCreate(['locale' => $locale], ['name' => $name]);
                }

                return $country;
            });
            $country->load('currency');
        }

        return $this->country = $country;
    }

    public function countryId(): int
    {
        return (int) $this->country()->id;
    }

    public function isInternational(Country|int|null $country): bool
    {
        $id = $country instanceof Country ? $country->id : $country;

        return $id !== null && (int) $id === $this->countryId();
    }

    public function currency(): Currency
    {
        return $this->country()->currency ?? $this->ensureCurrency();
    }

    public function code(): string
    {
        return (string) $this->currency()->code;
    }

    public function symbol(): string
    {
        return (string) ($this->currency()->symbol ?: $this->code());
    }

    public function decimals(): int
    {
        return (int) ($this->currency()->decimal_places ?? 2);
    }

    /** "12.50 USD" — the way freelance messages and errors print an amount. */
    public function format(int $minor): string
    {
        $d = $this->decimals();

        return number_format($minor / (10 ** $d), $d).' '.$this->code();
    }

    /**
     * The owner's own country when its money already IS the freelance currency (an American client or freelancer, with dollars as the
     * local currency): they work in their local wallet, so no international wallet is made for them (owner 2026-11-04). Null for
     * everyone else — their freelance money lives in the international wallet.
     */
    public function localCountryFor(?Model $owner): ?Country
    {
        $id = $owner === null ? null : \Modules\Freelance\Services\Features\FreelanceCountry::of($owner instanceof \Illuminate\Contracts\Auth\Authenticatable ? $owner : null);
        if ($id === null || $this->isInternational($id)) {
            return null;
        }
        $country = Country::query()->with('currency')->find($id);

        return $country !== null && strtoupper((string) $country->currency?->code) === $this->code() ? $country : null;
    }

    /** The country whose wallet carries this owner's freelance money: their own (same currency) or the international one. */
    public function walletCountryFor(?Model $owner): Country
    {
        return $this->localCountryFor($owner) ?? $this->country();
    }

    /** The owner's freelance wallet (created on first use, zero balance): the international one, or the local one when it is the same currency. */
    public function wallet(Model $owner): Wallet
    {
        return $this->wallets->firstOrCreateWallet($owner, $this->walletCountryFor($owner))->fresh(['currency']);
    }

    /** The owner's freelance wallet if it exists — reading never creates one. */
    public function existingWallet(Model $owner): ?Wallet
    {
        return $owner->getKey() === null ? null : Wallet::query()->with('currency')
            ->where('owner_type', \Modules\Wallet\Support\OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('country_id', $this->walletCountryFor($owner)->id)
            ->first();
    }

    /** `{currency, currency_symbol, decimals}` — what the apps need to print any amount. */
    public function meta(): array
    {
        return ['currency' => $this->code(), 'currency_symbol' => $this->symbol(), 'decimals' => $this->decimals()];
    }

    private function ensureCurrency(): Currency
    {
        $code = strtoupper((string) config('freelance.money.currency', 'USD'));

        return Currency::query()->withTrashed()->firstOrCreate(['code' => $code], [
            'symbol' => $code === 'USD' ? '$' : $code, 'decimal_places' => 2, 'exchange_rate' => 1, 'is_default' => false, 'status' => true,
        ]);
    }
}
