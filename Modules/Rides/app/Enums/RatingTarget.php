<?php

namespace Modules\Rides\Enums;

/** Who a rating tag describes. */
enum RatingTarget: string
{
    case Driver = 'driver';
    case Rider = 'rider';
}
