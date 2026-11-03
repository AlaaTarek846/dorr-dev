<?php

namespace Modules\WatchEarn\Enums;

/**
 * An ad's life (WE-020). `active` with a start date still ahead shows as scheduled. Closed for good: rejected,
 * ended (its end date passed), stopped (by the advertiser) — their reserved budget goes back to the free balance.
 * `exhausted` (budget spent) comes back to life when the advertiser adds budget.
 */
enum AdStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Active = 'active';
    case Paused = 'paused';
    case Exhausted = 'exhausted';
    case Suspended = 'suspended';
    case Ended = 'ended';
    case Stopped = 'stopped';

    /** Its budget is locked (D14): it can still run, or is waiting for a review. */
    public function holdsBudget(): bool
    {
        return in_array($this, [self::PendingReview, self::Active, self::Paused, self::Exhausted, self::Suspended], true);
    }

    public function closed(): bool
    {
        return in_array($this, [self::Rejected, self::Ended, self::Stopped], true);
    }
}
