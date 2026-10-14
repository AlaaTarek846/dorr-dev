<?php

namespace Modules\Rides\Enums;

/** Who carries a tax — the driver in Saudi Arabia (same as Jawad). */
enum TaxBearer: string
{
    case Driver = 'driver';
    case Rider = 'rider';
}
