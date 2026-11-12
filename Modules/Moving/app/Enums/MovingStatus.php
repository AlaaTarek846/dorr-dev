<?php

namespace Modules\Moving\Enums;

/**
 * A move request from the first draft to the end (docs/moving_business.md, the request states). The names are the
 * ones the customer sees, in that order; expired, withdrawn, cancelled and disputed are the side roads.
 */
enum MovingStatus: string
{
    case Draft = 'draft';
    case Open = 'open';                 // published, taking offers
    case Expired = 'expired';           // no offer chosen in time (REQ-020)
    case Withdrawn = 'withdrawn';       // the customer took it back before choosing (REQ-015)
    case Confirmed = 'confirmed';       // an offer accepted and paid (held), waiting for a driver
    case Assigned = 'assigned';
    case DriverOnWay = 'driver_on_way';
    case Arrived = 'arrived';
    case Loading = 'loading';
    case InTransit = 'in_transit';
    case Unloading = 'unloading';
    case Delivered = 'delivered';       // the objection window is running
    case Completed = 'completed';       // the money went to the establishment
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    /** An offer was accepted and the job is not over. */
    public function isActiveJob(): bool
    {
        return in_array($this, [self::Confirmed, self::Assigned, self::DriverOnWay, self::Arrived, self::Loading, self::InTransit, self::Unloading], true);
    }
}
