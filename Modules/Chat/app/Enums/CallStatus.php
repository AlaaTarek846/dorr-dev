<?php

namespace Modules\Chat\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case Ongoing = 'ongoing';
    case Ended = 'ended';
    case Missed = 'missed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Ringing, self::Ongoing], true);
    }
}
