<?php

namespace Modules\Rides\Enums;

/** Fixed before the ride, or by the distance actually driven — an admin option; open-destination rides are always metered. */
enum PricingMode: string
{
    case Upfront = 'upfront';
    case Metered = 'metered';
}
