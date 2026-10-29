<?php

namespace Modules\Chat\Enums;

/** What an advertising deal takes off the verification fee (REQ-04-01). */
enum VerificationExemptionType: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Fixed = 'fixed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
