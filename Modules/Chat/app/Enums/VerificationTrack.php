<?php

namespace Modules\Chat\Enums;

/** The two ways to the verification badge (REQ-01-01): pay for a package, or an advertising deal with Dorr. */
enum VerificationTrack: string
{
    case Paid = 'paid';
    case Celebrity = 'celebrity';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
