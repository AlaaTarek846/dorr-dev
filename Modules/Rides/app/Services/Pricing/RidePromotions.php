<?php

namespace Modules\Rides\Services\Pricing;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\User\Models\User;
use Modules\Wallet\Models\WalletCoupon;
use Modules\Wallet\Support\OwnerType;

/**
 * A wallet coupon on a ride (rides_tasks R-45). The wallet's checkout refuses rides — a held ride has
 * no price to discount yet — so the coupon goes on the quote instead: priced there as its own
 * `discount` line, spent when the ride is confirmed, and given back if the ride ends without a trip.
 * DORR pays the discount; the driver's net never moves.
 */
class RidePromotions
{
    public const PURPOSE = 'ride';

    /**
     * The coupon the rider typed, if it can be used for a ride in this country now.
     *
     * @throws RidesException coupon_invalid | coupon_expired | coupon_used | coupon_not_here
     */
    public function find(User $rider, int $countryId, string $code): WalletCoupon
    {
        $coupon = WalletCoupon::query()->where('code', strtoupper(trim($code)))->first();
        $this->assertUsable($coupon, $rider, $countryId);

        return $coupon;
    }

    /**
     * What the quote keeps of the coupon, so the price never depends on the coupon changing later.
     *
     * @return array{id: int, code: string, kind: string, value: int, max_discount_minor: ?int, min_amount_minor: ?int}
     */
    public static function snapshot(WalletCoupon $coupon): array
    {
        return [
            'id' => $coupon->id, 'code' => $coupon->code, 'kind' => $coupon->kind, 'value' => (int) $coupon->value,
            'max_discount_minor' => $coupon->max_discount_minor, 'min_amount_minor' => $coupon->min_amount_minor,
        ];
    }

    /**
     * Inside the confirmation's transaction: checks the coupon again and spends one use for the ride.
     *
     * @param  array{id: int}  $snapshot
     */
    public function redeem(Ride $ride, array $snapshot, int $discountMinor): void
    {
        $coupon = WalletCoupon::query()->lockForUpdate()->find($snapshot['id']);
        $rider = $ride->rider()->firstOrFail();
        $this->assertUsable($coupon, $rider, $ride->country_id);

        DB::table('wallet_coupon_redemptions')->insert([
            'coupon_id' => $coupon->id, 'checkout_id' => null, 'ride_id' => $ride->id,
            'owner_type' => OwnerType::aliasFor($rider), 'owner_id' => $ride->user_id,
            'discount_minor' => max(0, $discountMinor), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $coupon->used_count++;
        if ($coupon->used_count >= $coupon->usage_limit) {
            $coupon->status = 'used';
        }
        $coupon->save();
    }

    /** At the end: the discount the rider really got (a metered ride's differs from the quote's). */
    public function settle(Ride $ride, int $discountMinor): void
    {
        DB::table('wallet_coupon_redemptions')->where('ride_id', $ride->id)->update(['discount_minor' => max(0, $discountMinor), 'updated_at' => now()]);
    }

    /** The ride ended without a trip (cancelled free, expired, no driver): the use comes back. */
    public function release(Ride $ride): void
    {
        if ($ride->status === RideStatus::Completed) {
            return;
        }
        $rows = DB::table('wallet_coupon_redemptions')->where('ride_id', $ride->id)->get();
        foreach ($rows as $row) {
            $coupon = WalletCoupon::query()->lockForUpdate()->find($row->coupon_id);
            DB::table('wallet_coupon_redemptions')->where('id', $row->id)->delete();
            if ($coupon === null) {
                continue;
            }
            $coupon->used_count = max(0, $coupon->used_count - 1);
            if ($coupon->status === 'used' && $coupon->used_count < $coupon->usage_limit) {
                $coupon->status = 'active';
            }
            $coupon->save();
        }
    }

    private function assertUsable(?WalletCoupon $coupon, User $rider, int $countryId): void
    {
        $mine = $coupon !== null && ($coupon->owner_type === null || ($coupon->owner_type === OwnerType::aliasFor($rider) && (int) $coupon->owner_id === (int) $rider->getKey()));
        if (! $mine || $coupon->status === 'revoked' || ($coupon->starts_at && $coupon->starts_at->isFuture())) {
            throw new RidesException('coupon_invalid', 422);
        }
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            throw new RidesException('coupon_expired', 422);
        }
        if ($coupon->status === 'used' || $coupon->used_count >= $coupon->usage_limit) {
            throw new RidesException('coupon_used', 422);
        }
        if (($coupon->country_id !== null && (int) $coupon->country_id !== $countryId)
            || (! empty($coupon->purposes) && ! in_array(self::PURPOSE, $coupon->purposes, true))) {
            throw new RidesException('coupon_not_here', 422);
        }
    }
}
