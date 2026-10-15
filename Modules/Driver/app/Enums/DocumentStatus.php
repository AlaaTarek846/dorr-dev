<?php

namespace Modules\Driver\Enums;

/** One uploaded document; an approved one is 'replaced' when a newer one of the same type is approved. */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Replaced = 'replaced';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
