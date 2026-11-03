<?php

namespace Modules\Freelance\Services\Plans;

use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOfferCredit;
use Modules\Freelance\Models\FreelanceOfferPack;
use Modules\Freelance\Models\FreelanceOfferPackPrice;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;

/**
 * Extra offers for sale (FL-L01, phase 2). When the month's free offers are used up a freelancer can buy a pack from their own wallet (the
 * spend-only part first); the money is booked as income of the platform and the offers become a batch that is spent after the free ones
 * and ends after `freelance.offer_credit_valid_days` (see [FreelanceOfferCredits]). A plan with no limit has no use for a pack, so it is refused.
 * The app sends a `request_id` with each tap: the same one again is the same purchase and is never charged twice.
 */
class FreelanceOfferPacks
{
    public function __construct(
        private readonly FreelanceFeatures $features,
        private readonly FreelanceOfferCredits $credits,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
    ) {}

    /** Packs are sold where their own switch is on and the free-offers limit exists at all. */
    public function enabled(int $countryId): bool
    {
        return $this->features->allows($countryId, 'offer_packs') && $this->features->allows($countryId, 'free_offer_quota');
    }

    /**
     * What the app shows: the packs of the freelancer's country with their price, what they have left, and the last purchases.
     *
     * @return array<string, mixed>
     */
    public function catalog(Freelancer $freelancer): array
    {
        $country = $this->countryOf($freelancer);
        $enabled = $country !== null && $this->enabled($country->id);
        $currency = $country?->currency;
        $out = [
            'enabled' => $enabled, 'currency' => $currency?->code, 'currency_symbol' => $currency?->symbol, 'decimals' => (int) ($currency?->decimal_places ?? 2), 'unlimited' => false,
            'valid_days' => (int) config('freelance.offer_credit_valid_days', 0), 'credits' => $this->credits->summary($freelancer), 'packs' => [], 'purchases' => [],
        ];
        if (! $enabled) {
            return $out;
        }

        $out['unlimited'] = app(FreelancerOffers::class)->quota($freelancer)['limit'] === null;
        $prices = FreelanceOfferPackPrice::query()->where('country_id', $country->id)->where('status', true)->pluck('price_minor', 'pack_id');
        $out['packs'] = FreelanceOfferPack::query()->where('status', true)->whereIn('id', $prices->keys())->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FreelanceOfferPack $p) => ['slug' => $p->slug, 'name' => $p->text(), 'offers' => $p->offers, 'price_minor' => (int) $prices[$p->id]])->all();
        $out['purchases'] = FreelanceOfferCredit::query()->where('freelancer_id', $freelancer->id)->with('pack')->latest('id')->limit(20)->get()->map(fn (FreelanceOfferCredit $c) => [
            'uuid' => $c->uuid, 'pack' => $c->pack?->text(), 'quantity' => $c->quantity, 'price_minor' => $c->price_minor, 'currency' => $c->currency_code,
            'expires_at' => $c->expires_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
        ])->all();

        return $out;
    }

    /**
     * Buys a pack.
     *
     * @throws FreelanceException offer_packs_closed · offer_pack_unavailable · offer_pack_not_needed · offer_pack_insufficient
     */
    public function buy(Freelancer $freelancer, string $slug, ?string $requestId = null): FreelanceOfferCredit
    {
        $country = $this->countryOf($freelancer);
        if ($country === null || ! $this->enabled($country->id)) {
            throw new FreelanceException('offer_packs_closed', 403);
        }
        $pack = FreelanceOfferPack::query()->where('slug', $slug)->where('status', true)->first();
        $price = $pack === null ? null : FreelanceOfferPackPrice::query()->where('pack_id', $pack->id)->where('country_id', $country->id)->where('status', true)->value('price_minor');
        if ($pack === null || $price === null) {
            throw new FreelanceException('offer_pack_unavailable', 422);
        }
        $price = (int) $price;
        $requestId = $requestId === null || $requestId === '' ? null : Str::limit($requestId, 64, '');

        return DB::transaction(function () use ($freelancer, $country, $pack, $price, $requestId) {
            // One purchase at a time per freelancer: a double tap waits here and then finds the first one done.
            Freelancer::query()->whereKey($freelancer->id)->lockForUpdate()->first();
            if ($requestId !== null) {
                $done = FreelanceOfferCredit::query()->where('freelancer_id', $freelancer->id)->where('request_id', $requestId)->first();
                if ($done !== null) {
                    return $done;
                }
            }
            if (app(FreelancerOffers::class)->quota($freelancer)['limit'] === null) {
                throw new FreelanceException('offer_pack_not_needed', 409);
            }

            $uuid = (string) Str::uuid();
            $wallet = $this->wallets->firstOrCreateWallet($freelancer, $country)->fresh(['currency']);
            $available = max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
            if ($available < $price) {
                throw new FreelanceException('offer_pack_insufficient', 422, [
                    'needed' => $this->money($price, $country), 'available' => $this->money($available, $country),
                ], ['needed_minor' => $price, 'available_minor' => $available, 'shortfall_minor' => $price - $available, 'currency' => $country->currency?->code]);
            }

            $transaction = $this->wallets->debitSpendOnlyFirst($wallet, $price, WalletTransactionType::ServicePayment, [
                'reference_type' => 'freelance_offer_pack', 'reference_id' => $pack->id, 'idempotency_key' => 'freelance:offer-pack:'.$uuid,
                'notes' => ['key' => 'wallet.notes.freelance_offer_pack', 'variables' => ['project' => (string) $pack->text().' ('.$pack->offers.')']],
            ]);

            $days = (int) config('freelance.offer_credit_valid_days', 0);
            $credit = FreelanceOfferCredit::query()->create([
                'uuid' => $uuid, 'freelancer_id' => $freelancer->id, 'pack_id' => $pack->id, 'country_id' => $country->id, 'request_id' => $requestId,
                'quantity' => $pack->offers, 'price_minor' => $price, 'currency_code' => (string) $country->currency?->code,
                'wallet_transaction_id' => $transaction->id, 'expires_at' => $days > 0 ? now()->addDays($days) : null,
            ]);
            $this->ledger->offerPack($price, $country->currency, $country, $credit, (string) $pack->text().' ('.$pack->offers.')', $transaction);

            return $credit;
        });
    }

    private function countryOf(Freelancer $freelancer): ?Country
    {
        return $freelancer->country_id === null ? null : Country::query()->with('currency')->find($freelancer->country_id);
    }

    private function money(int $minor, Country $country): string
    {
        $decimals = (int) ($country->currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $decimals), $decimals).' '.$country->currency?->code;
    }
}
