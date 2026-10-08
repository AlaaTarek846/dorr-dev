<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Wallet\Exceptions\CheckoutException;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Models\WalletCoupon;
use Modules\Wallet\Support\OwnerType;

/**
 * Coupons on the one payment screen: issued (a prize, or by the admin), put on a checkout (the
 * charge becomes amount − discount), and spent when the checkout is paid — exactly once per use.
 */
class CouponService
{
    /**
     * @param  array<string, mixed>  $options  max_discount_minor, min_amount_minor, purposes, expires_at, usage_limit, source, source_id
     */
    public function issue(?Model $owner, ?Country $country, string $kind, int $value, array $options = []): WalletCoupon
    {
        do {
            $code = 'DORR-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
        } while (WalletCoupon::query()->where('code', $code)->exists());

        return WalletCoupon::query()->create([
            'code' => $code,
            'owner_type' => $owner ? OwnerType::aliasFor($owner) : null,
            'owner_id' => $owner?->getKey(),
            'country_id' => $country?->id,
            'kind' => $kind,
            'value' => $value,
            'max_discount_minor' => $options['max_discount_minor'] ?? null,
            'min_amount_minor' => $options['min_amount_minor'] ?? null,
            'purposes' => $options['purposes'] ?? null,
            'starts_at' => $options['starts_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'usage_limit' => $options['usage_limit'] ?? 1,
            'source' => $options['source'] ?? 'admin',
            'source_id' => $options['source_id'] ?? null,
            'status' => 'active',
        ]);
    }

    /** Put a coupon on a checkout I haven't paid yet. */
    public function apply(Model $owner, Checkout $checkout, string $code): Checkout
    {
        if ($checkout->status->value !== 'pending' || $checkout->payment_transaction_id !== null) {
            throw CheckoutException::notPending();
        }
        $coupon = WalletCoupon::query()->where('code', strtoupper(trim($code)))->first();
        $this->assertUsable($coupon, $owner, $checkout);
        $checkout->update(['coupon_id' => $coupon->id, 'discount_minor' => $coupon->discountOn($checkout->amount_minor)]);

        return $checkout->refresh();
    }

    public function remove(Checkout $checkout): Checkout
    {
        if ($checkout->status->value === 'pending' && $checkout->payment_transaction_id === null) {
            $checkout->update(['coupon_id' => null, 'discount_minor' => 0]);
        }

        return $checkout->refresh();
    }

    /**
     * Inside the checkout's settle transaction: re-check and spend the coupon. Returns the discount.
     */
    public function redeem(Checkout $checkout): int
    {
        if ($checkout->coupon_id === null) {
            return 0;
        }
        $coupon = WalletCoupon::query()->lockForUpdate()->find($checkout->coupon_id);
        $owner = $checkout->owner();
        $this->assertUsable($coupon, $owner, $checkout);
        $discount = $coupon->discountOn($checkout->amount_minor);

        DB::table('wallet_coupon_redemptions')->insert([
            'coupon_id' => $coupon->id, 'checkout_id' => $checkout->id, 'owner_type' => OwnerType::aliasFor($owner), 'owner_id' => $owner->getKey(),
            'discount_minor' => $discount, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $coupon->used_count++;
        if ($coupon->used_count >= $coupon->usage_limit) {
            $coupon->status = 'used';
        }
        $coupon->save();
        if ($discount !== (int) $checkout->discount_minor) {
            $checkout->forceFill(['discount_minor' => $discount])->save();
        }

        return $discount;
    }

    /**
     * My coupons that can still be used.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Model $owner): array
    {
        return WalletCoupon::query()->with('country.currency')
            ->where('owner_type', OwnerType::aliasFor($owner))->where('owner_id', $owner->getKey())
            ->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->get()->map(fn (WalletCoupon $c) => $this->present($c))->all();
    }

    /** @return array<string, mixed> */
    public function present(WalletCoupon $c): array
    {
        return [
            'code' => $c->code, 'kind' => $c->kind, 'value' => $c->value, 'currency_code' => $c->country?->currency?->code,
            'max_discount_minor' => $c->max_discount_minor, 'min_amount_minor' => $c->min_amount_minor,
            'expires_at' => $c->expires_at?->toIso8601String(), 'source' => $c->source, 'status' => $c->status,
        ];
    }

    private function assertUsable(?WalletCoupon $coupon, Model $owner, Checkout $checkout): void
    {
        $mine = $coupon !== null && ($coupon->owner_type === null || ($coupon->owner_type === OwnerType::aliasFor($owner) && (int) $coupon->owner_id === (int) $owner->getKey()));
        if (! $mine || $coupon->status === 'revoked' || ($coupon->starts_at && $coupon->starts_at->isFuture())) {
            throw new CheckoutException('coupon_invalid', 'wallet.errors.coupon_invalid', 422);
        }
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            throw new CheckoutException('coupon_expired', 'wallet.errors.coupon_expired', 422);
        }
        if ($coupon->status === 'used' || $coupon->used_count >= $coupon->usage_limit) {
            throw new CheckoutException('coupon_used', 'wallet.errors.coupon_used', 422);
        }
        if (($coupon->country_id !== null && (int) $coupon->country_id !== (int) $checkout->country_id)
            || (! empty($coupon->purposes) && ! in_array($checkout->purpose, $coupon->purposes, true))) {
            throw new CheckoutException('coupon_not_here', 'wallet.errors.coupon_not_here', 422);
        }
        if ($coupon->min_amount_minor !== null && $checkout->amount_minor < $coupon->min_amount_minor) {
            throw new CheckoutException('coupon_min_amount', 'wallet.errors.coupon_min_amount', 422);
        }
    }
}
