<?php

namespace Modules\Marketing\Checkout;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingPackage;
use Modules\Marketing\Models\MarketingSubscription;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Packages\MarketingEntitlement;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * One period of the marketing package — `{package: code}` — on the app's payment screen (card or wallet). Priced per
 * country; sold only where the service is on. Buying again before the end adds the next period after the paid one.
 */
class MarketingPackagePurpose implements CheckoutPurpose
{
    public const KEY = 'marketing_package';

    public function __construct(
        private readonly MarketingFeatures $features,
        private readonly MarketingEntitlement $entitlement,
        private readonly MarketingAudit $audit,
    ) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        if (MarketingCountry::of($owner) !== $country->id || ! $this->features->allows($country->id, MarketingFeatures::SERVICE)) {
            throw new MarketingException('feature_off', 403, [], ['feature' => MarketingFeatures::SERVICE]);
        }

        $package = $this->package((string) ($reference['package'] ?? ''));
        $price = $package->priceIn($country->id) ?? throw new MarketingException('package_unavailable', 422);

        return new CheckoutLine(
            amountMinor: $price->price_minor,
            title: __('marketing.checkout.title', ['name' => $package->localized('name')]),
            subtitle: __('marketing.checkout.subtitle', ['days' => $package->period_days]),
            reference: ['package' => $package->code],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $owner = $checkout->owner();
        $package = MarketingPackage::query()->where('code', (string) $checkout->referenceValue('package'))->withTrashed()->firstOrFail();
        $starts = $this->entitlement->paidUntil($owner) ?? now();

        $subscription = MarketingSubscription::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'package_id' => $package->id,
            'country_id' => $checkout->country_id,
            'status' => MarketingSubscription::ACTIVE,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addDays(max(1, $package->period_days ?: (int) config('marketing.default_period_days', 30))),
            'price_minor' => $checkout->payableMinor(),
            'currency_code' => (string) ($checkout->currency?->code ?? ''),
            'checkout_id' => $checkout->id,
        ]);

        $this->audit->record(null, $owner, 'package.purchased', $subscription, meta: ['package' => $package->code, 'checkout' => $checkout->uuid]);
    }

    private function package(string $code): MarketingPackage
    {
        return MarketingPackage::query()->where('code', $code)->where('is_active', true)->with('prices')->first()
            ?? throw new MarketingException('package_unavailable', 422);
    }
}
