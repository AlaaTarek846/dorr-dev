<?php

namespace Modules\Rides\Services\Bidding;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\BidStatus;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideOffer;

/**
 * The offers on the rider's screen (docs/rides-plan.md §9.2.3): each lives `bids.ttl_s` from when it
 * shows; past `bids.max_visible` (0 = no limit) the rest wait in line and their timer starts when
 * they show. A driver holds one live bid at a time — the `driver_bid:{id}` lock row.
 */
final class BidBoard
{
    /** Shows queued offers while there is room (each one's timer starts now). */
    public static function promote(Ride $ride): void
    {
        $max = (int) $ride->policy('bids.max_visible', 0);
        $ttl = (int) $ride->policy('bids.ttl_s', 20);
        $visible = RideBid::query()->where('ride_id', $ride->id)->where('status', BidStatus::Pending->value)->count();
        $queued = RideBid::query()->where('ride_id', $ride->id)->where('status', BidStatus::Queued->value)->orderBy('bid_price_minor')->orderBy('id')->get();

        foreach ($queued as $bid) {
            if ($max > 0 && $visible >= $max) {
                break;
            }
            $bid->update(['status' => BidStatus::Pending, 'shown_at' => now(), 'expires_at' => now()->addSeconds($ttl)]);
            $visible++;
        }
    }

    /** Every few seconds: offers whose timer ran out are declined by themselves (§9.2.3). */
    public static function expireDue(): void
    {
        $due = RideBid::query()->where('status', BidStatus::Pending->value)->where('expires_at', '<=', now())->get();
        foreach ($due as $bid) {
            self::finish($bid, BidStatus::Expired);
        }
        $due->pluck('ride_id')->unique()->each(fn (int $id) => ($ride = Ride::query()->find($id)) && self::promote($ride));
    }

    /** One bid ends (declined, expired, withdrawn…): its driver may bid again. */
    public static function finish(RideBid $bid, BidStatus $status): void
    {
        DB::transaction(function () use ($bid, $status) {
            $updated = RideBid::query()->whereKey($bid->id)->whereIn('status', [BidStatus::Queued->value, BidStatus::Pending->value])
                ->update(['status' => $status->value, 'responded_at' => now()]);
            if ($updated > 0) {
                RideActiveLock::query()->whereKey(RideActiveLock::forDriverBid($bid->driver_id))->where('ride_id', $bid->ride_id)->delete();
                // Declined or timed out: the driver may offer again (§9.2.4), so the request stays a while longer.
                RideOffer::query()->where('ride_id', $bid->ride_id)->where('driver_id', $bid->driver_id)->where('status', OfferStatus::Sent->value)
                    ->where('expires_at', '<', now()->addSeconds(30))->update(['expires_at' => now()->addSeconds(30)]);
            }
        });
    }

    /** The ride is agreed, cancelled or expired: every other offer is withdrawn and the drivers freed. */
    public static function closeRide(Ride $ride, ?RideBid $chosen = null): void
    {
        $chosen?->update(['status' => BidStatus::Accepted, 'responded_at' => now()]);
        RideBid::query()->where('ride_id', $ride->id)->whereIn('status', [BidStatus::Queued->value, BidStatus::Pending->value])
            ->when($chosen, fn ($q) => $q->whereKeyNot($chosen->id))
            ->update(['status' => BidStatus::Withdrawn->value, 'responded_at' => now()]);
        RideActiveLock::query()->where('ride_id', $ride->id)->where('lock_key', 'like', 'driver_bid:%')->delete();
    }
}
