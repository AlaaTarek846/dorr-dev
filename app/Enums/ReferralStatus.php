<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Registered = 'registered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
