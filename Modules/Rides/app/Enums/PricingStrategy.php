<?php

namespace Modules\Rides\Enums;

/** How a ride category is priced (docs/rides-structure.md §2.1). */
enum PricingStrategy: string
{
    case Distance = 'distance';
    case TripFlat = 'trip_flat';
    case Bid = 'bid';
    case Seat = 'seat';
    case Time = 'time';
}
