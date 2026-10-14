<?php

namespace Modules\Driver\Enums;

/** What kind of vehicle — ride categories pick the body types they accept. */
enum VehicleBodyType: string
{
    case Sedan = 'sedan';
    case Suv = 'suv';
    case Van = 'van';
    case Minibus = 'minibus';
    case Bus = 'bus';
    case Motorcycle = 'motorcycle';
    case Boat = 'boat';
    case Yacht = 'yacht';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
