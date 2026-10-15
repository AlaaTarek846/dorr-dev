<?php

namespace Modules\Rides\Enums;

/** Who cancelled a ride. */
enum CancelActor: string
{
    case Rider = 'rider';
    case Driver = 'driver';
    case Platform = 'platform';
    case System = 'system';
}
