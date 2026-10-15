<?php

namespace Modules\Rides\Enums;

/** What a zone polygon means. */
enum ZoneType: string
{
    case ServiceArea = 'service_area';
    case Blocked = 'blocked';
    case Airport = 'airport';
    case Surcharge = 'surcharge';
    case PickupOnly = 'pickup_only';
}
