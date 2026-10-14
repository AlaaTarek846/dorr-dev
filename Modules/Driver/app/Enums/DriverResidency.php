<?php

namespace Modules\Driver\Enums;

/**
 * Citizen or resident of the country the driver works in — set by the admin, in every country.
 * Decides which documents are required (a requirement may be for one of them, or both).
 */
enum DriverResidency: string
{
    case Citizen = 'citizen';
    case Resident = 'resident';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
