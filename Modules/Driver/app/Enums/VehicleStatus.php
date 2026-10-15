<?php

namespace Modules\Driver\Enums;

/** A vehicle's review state; only an approved one can be the active vehicle. */
enum VehicleStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
