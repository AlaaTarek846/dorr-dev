<?php

namespace Modules\Rides\Enums;

/** How a ride was booked. */
enum BookingMode: string
{
    case Instant = 'instant';
    case Scheduled = 'scheduled';
    case Bid = 'bid';
}
