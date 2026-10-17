<?php

namespace Modules\Rides\Enums;

enum CaseStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case NeedsInfo = 'needs_info';
    case Confirmed = 'confirmed';
    case PartiallyConfirmed = 'partially_confirmed';
    case Rejected = 'rejected';

    public function isDecided(): bool
    {
        return in_array($this, [self::Confirmed, self::PartiallyConfirmed, self::Rejected], true);
    }
}
