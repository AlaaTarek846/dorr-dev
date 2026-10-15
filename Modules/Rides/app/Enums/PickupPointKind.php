<?php

namespace Modules\Rides\Enums;

/** What an approved pickup point is (docs/rides-structure.md §2.7). */
enum PickupPointKind: string
{
    case Gate = 'gate';
    case Terminal = 'terminal';
    case Entrance = 'entrance';
    case Parking = 'parking';
}
