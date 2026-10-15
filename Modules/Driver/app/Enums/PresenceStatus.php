<?php

namespace Modules\Driver\Enums;

/** Where a driver is in their working day right now. */
enum PresenceStatus: string
{
    case Offline = 'offline';
    case Online = 'online';
    case Paused = 'paused';
    case OnTrip = 'on_trip';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
