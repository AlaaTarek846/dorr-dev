<?php

namespace Modules\Rides\Enums;

/**
 * A ride's state (docs/rides-structure.md §11). Every change goes through RideStateMachine, which
 * allows only the moves listed in `next()`.
 */
enum RideStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Scheduled = 'scheduled';
    case Searching = 'searching';
    case Bidding = 'bidding';
    case Accepted = 'accepted';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Searching, self::Bidding, self::Scheduled, self::Expired, self::Canceled],
            // Accepted straight away = the captain who reserved it heads to the pickup (D-268).
            self::Scheduled => [self::Searching, self::Bidding, self::Accepted, self::Canceled, self::Expired],
            self::Searching, self::Bidding => [self::Accepted, self::Expired, self::Canceled],
            // Back to Searching = the driver stepped back for an allowed reason, or the rider changed driver.
            self::Accepted => [self::Arrived, self::Searching, self::Canceled],
            self::Arrived => [self::InProgress, self::NoShow, self::Canceled],
            self::InProgress => [self::Completed, self::Canceled],
            self::Completed, self::Canceled, self::NoShow, self::Expired => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** A driver is with this ride (it holds the driver's lock). */
    public function hasDriver(): bool
    {
        return in_array($this, [self::Accepted, self::Arrived, self::InProgress], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => ! $s->isFinal() && $s !== self::Scheduled));
    }
}
