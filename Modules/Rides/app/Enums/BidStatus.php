<?php

namespace Modules\Rides\Enums;

/** A driver's offer on a bidding ride. Queued = waiting for room on the rider's screen (its timer hasn't started). */
enum BidStatus: string
{
    case Queued = 'queued';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::Pending;
    }
}
