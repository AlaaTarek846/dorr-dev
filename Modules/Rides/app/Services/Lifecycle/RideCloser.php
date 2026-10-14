<?php

namespace Modules\Rides\Services\Lifecycle;

use Modules\Driver\Models\Driver;
use Modules\Driver\Services\PresenceService;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Bidding\BidBoard;
use Modules\Rides\Services\Pricing\RidePromotions;
use Modules\Wallet\Enums\CheckoutStatus;
use Modules\Wallet\Models\Checkout;

/**
 * What every ending (completed, cancelled, no-show, expired) shares: the locks go, the driver is
 * free again, a card ride's payment screen that was never paid can't be paid any more, and a coupon
 * on a ride that didn't happen is given back.
 */
class RideCloser
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly RidePromotions $promotions,
    ) {}

    public function close(Ride $ride): void
    {
        BidBoard::closeRide($ride);
        RideActiveLock::query()->where('ride_id', $ride->id)->delete();
        RideOffer::query()->where('ride_id', $ride->id)->where('status', 'sent')->update(['status' => 'withdrawn', 'responded_at' => now()]);
        // A coupon spent on a ride that never took place comes back to the rider (R-45).
        $this->promotions->release($ride);

        // Every payment screen opened for this ride (the server's, or one the app opened itself) can't
        // be paid any more. A gateway payment already started still lands in the wallet (it then fails).
        if ($ride->payment_method->holdsInWallet()) {
            Checkout::query()->where('purpose', 'ride')->where('reference->ride_uuid', $ride->uuid)
                ->where('status', CheckoutStatus::Pending->value)->whereNull('payment_transaction_id')
                ->update(['status' => CheckoutStatus::Expired->value]);
        }

        if ($ride->driver_id !== null && ($driver = Driver::query()->find($ride->driver_id)) !== null) {
            $this->presence->rideEnded($driver);
        }
    }

    /** The driver stepped back before arriving: only their lock goes, the ride searches again. */
    public function releaseDriver(Ride $ride, Driver $driver): void
    {
        RideActiveLock::query()->whereKey(RideActiveLock::forDriver($driver->id))->where('ride_id', $ride->id)->delete();
        $this->presence->rideEnded($driver);
    }
}
