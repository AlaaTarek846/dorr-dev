<?php

namespace Modules\Driver\Enums;

/** A driver's account (D-2): signing up, under review, active, or held back — blocked is the only one that can't sign in. */
enum DriverStatus: string
{
    case PendingDocuments = 'pending_documents';
    case UnderReview = 'under_review';
    case Active = 'active';
    case Suspended = 'suspended';
    case Restricted = 'restricted';
    case Blocked = 'blocked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
