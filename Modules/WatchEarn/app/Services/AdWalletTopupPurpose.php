<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\ChargesItself;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;
use Modules\WatchEarn\Exceptions\WatchEarnException;

/**
 * WE-013 — topping the ad wallet up on the app's one payment screen: by card (a gateway top-up of the wallet, then
 * on into the ad wallet as withdrawable, D12) or from the wallet's balance. The app sends `reference: {amount_minor}`.
 * It charges itself (no DORR revenue: the money is still the advertiser's until views spend it).
 */
class AdWalletTopupPurpose implements ChargesItself, CheckoutPurpose
{
    public const KEY = 'watch_earn_ad_wallet';

    /** One top-up is at most this much (minor units) — a guard against a typo, not a business limit. */
    private const MAX_MINOR = 100_000_000;

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $amount = (int) ($reference['amount_minor'] ?? 0);
        if (! $owner instanceof User || $amount <= 0 || $amount > self::MAX_MINOR) {
            throw new WatchEarnException('invalid_amount');
        }

        return new CheckoutLine($amount, __('watch_earn.checkout.title'), __('watch_earn.checkout.subtitle'), ['amount_minor' => $amount]);
    }

    public function fulfil(Checkout $checkout): void
    {
        $user = $checkout->owner();
        if (! $user instanceof User) {
            throw new WatchEarnException('not_found', 404);
        }

        app(AdWalletService::class)->fromCheckout(
            $user,
            $checkout->country,
            $checkout->payableMinor(),
            $checkout->paid_via === 'gateway',
            (string) $checkout->uuid,
        );
    }
}
