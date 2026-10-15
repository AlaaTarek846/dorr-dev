<?php

namespace Modules\Driver\Enums;

/** Whether a driver may work in one DORR service (and, in Modules/Rides, one ride category). */
enum EligibilityStatus: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Suspended = 'suspended';
    case Pending = 'pending';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
